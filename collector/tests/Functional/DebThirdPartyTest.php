<?php

namespace App\Tests\Functional;

use App\Blacklist\Blacklist;
use App\Entity\PackageList;
use App\Entity\Source;
use App\Http\Downloader;
use App\Job\JobContext;
use App\Resolver\ArtifactCatalog;
use App\Resolver\DebResolver;
use App\Source\Sources;
use App\System\DebIndex;
use App\System\IndexFiles;
use App\System\Signatures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Sas\Bundle\BlobStore;
use Sas\Bundle\Ecosystem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\NilUuid;

/**
 * Dépôt apt tiers ajouté à Debian 12, contre des dépôts simulés (fixtures signées par une clé de test qui
 * remplace aussi les clés Debian) : index vérifié avec la clé du dépôt, dépendances résolues dans les
 * dépôts officiels, paquets marqués avec le nom du dépôt.
 */
final class DebThirdPartyTest extends KernelTestCase
{
    private const string FIXTURES = __DIR__.'/../fixtures/apt';
    private const string REPO = 'https://repo.acme.test/apt';

    private string $dir;

    /** @var array<string, string> contenu servi par adresse */
    private array $files = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-apt-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/keys', 0o777, true);
        $_ENV['STORAGE_DIR'] = $_SERVER['STORAGE_DIR'] = $this->dir.'/storage';
        putenv('STORAGE_DIR='.$this->dir.'/storage');
        self::bootKernel();
        $em = $this->em();
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = OFF');
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');

        // La clé de test remplace les clés de Debian 12 : les index officiels simulés sont signés avec elle
        foreach (['archive-key-12', 'release-12', 'archive-key-12-security'] as $name) {
            copy(self::FIXTURES.'/key.asc', $this->dir.'/keys/'.$name.'.asc');
        }
        foreach (['https://deb.debian.org/debian/dists/bookworm', 'https://deb.debian.org/debian/dists/bookworm-updates', 'https://security.debian.org/debian-security/dists/bookworm-security'] as $suite) {
            $this->files[$suite.'/InRelease'] = self::fixture('official-InRelease');
            $this->files[$suite.'/main/binary-amd64/Packages'] = self::fixture('official-Packages');
        }
        foreach (['https://deb.debian.org/debian', 'https://security.debian.org/debian-security'] as $base) {
            $this->files[$base.'/pool/main/b/base-files/base-files_12.4_amd64.deb'] = self::fixture('base-files.deb');
        }
        $this->files[self::REPO.'/dists/bookworm/stable/binary-amd64/Packages'] = self::fixture('thirdparty-Packages');
        $this->files[self::REPO.'/pool/stable/a/acme-tool_1.2.0_amd64.deb'] = self::fixture('acme-tool.deb');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testThirdPartyPackagesAreResolvedWithOfficialDependencies(): void
    {
        $this->files[self::REPO.'/dists/bookworm/InRelease'] = self::fixture('thirdparty-InRelease');
        $this->source();

        $artifacts = $this->resolver()->resolve($this->list('acme-tool'), $this->context());

        $byName = [];
        foreach ($artifacts as $artifact) {
            $byName[$artifact->getName()] = $artifact;
        }
        self::assertSame(['acme-tool', 'base-files'], array_keys($byName));
        self::assertSame('ACME', $byName['acme-tool']->getMetadata()['repository'] ?? null);
        self::assertSame('stable', $byName['acme-tool']->getMetadata()['component'] ?? null);
        self::assertSame('debian-12/pool/stable/a/acme-tool_1.2.0_amd64.deb', $byName['acme-tool']->getFilename());
        self::assertArrayNotHasKey('repository', $byName['base-files']->getMetadata());
    }

    /** Dépôts plus anciens : Release et sa signature détachée Release.gpg, sans InRelease */
    public function testReleaseWithDetachedSignature(): void
    {
        $this->files[self::REPO.'/dists/bookworm/Release'] = self::fixture('thirdparty-Release');
        $this->files[self::REPO.'/dists/bookworm/Release.gpg'] = self::fixture('thirdparty-Release.gpg');
        $this->source();

        $names = array_map(static fn ($a) => $a->getName(), $this->resolver()->resolve($this->list('acme-tool'), $this->context()));

        self::assertContains('acme-tool', $names);
    }

    public function testIndexSignedByAnotherKeyIsRefused(): void
    {
        $this->files[self::REPO.'/dists/bookworm/InRelease'] = self::fixture('thirdparty-InRelease-other-key');
        $this->source();

        $this->expectExceptionMessage('Signature invalide pour ACME');
        $this->resolver()->resolve($this->list('acme-tool'), $this->context());
    }

    public function testDisabledSourceIsIgnored(): void
    {
        $this->files[self::REPO.'/dists/bookworm/InRelease'] = self::fixture('thirdparty-InRelease');
        $source = $this->source();
        $source->update($source->getLabel(), $source->getUrl(), null, false, false);
        $this->em()->flush();
        static::getContainer()->get(Sources::class)->reset();

        $this->expectExceptionMessage('acme-tool');
        $this->resolver()->resolve($this->list('acme-tool'), $this->context());
    }

    private function source(): Source
    {
        $source = new Source(Ecosystem::Deb);
        $source->update('ACME', self::REPO, null, false, true);
        $source->setSystem('debian-12', 'bookworm', ['stable'], self::fixture('key.asc'));
        $this->em()->persist($source);
        $this->em()->flush();
        static::getContainer()->get(Sources::class)->reset();

        return $source;
    }

    private function resolver(): DebResolver
    {
        $c = static::getContainer();
        $http = new MockHttpClient(fn (string $method, string $url): MockResponse => isset($this->files[$url])
            ? new MockResponse($this->files[$url])
            : new MockResponse('', ['http_code' => 404]));
        $sources = $c->get(Sources::class);
        $index = new DebIndex(new IndexFiles($http, $this->dir.'/cache', $sources), new Signatures($this->dir.'/keys'), $sources);

        return new DebResolver($index, new Downloader($http, $c->get(BlobStore::class), $sources), $c->get(ArtifactCatalog::class), $c->get(Blacklist::class));
    }

    private function list(string $specs): PackageList
    {
        $list = new PackageList('Système', Ecosystem::Deb);
        $list->setSpecs($specs);
        $list->setOptions(['distribution' => 'debian-12', 'architectures' => ['amd64'], 'sections' => ['main']]);
        $this->em()->persist($list);

        return $list;
    }

    private function context(): JobContext
    {
        return new JobContext($this->em()->getConnection(), new NilUuid());
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.'/'.$name);
    }
}
