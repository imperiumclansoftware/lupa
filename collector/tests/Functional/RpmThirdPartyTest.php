<?php

namespace App\Tests\Functional;

use App\Blacklist\Blacklist;
use App\Entity\PackageList;
use App\Entity\Source;
use App\Http\Downloader;
use App\Job\JobContext;
use App\Resolver\ArtifactCatalog;
use App\Resolver\RpmResolver;
use App\Source\Sources;
use App\System\IndexFiles;
use App\System\RpmIndex;
use App\System\RpmSignature;
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
 * Dépôt dnf tiers à index non signé (comme EPEL) ajouté à Rocky Linux 9, contre des dépôts simulés :
 * l'index officiel (primary.xml seul, signé par une clé de test) et le vrai paquet epel-release, dont la
 * signature est vérifiée avec la clé EPEL 9.
 */
final class RpmThirdPartyTest extends KernelTestCase
{
    private const string FIXTURES = __DIR__.'/../fixtures/rpm';
    private const string BASEOS = 'https://dl.rockylinux.org/pub/rocky/9/BaseOS/x86_64/os';
    private const string EPEL = 'https://epel.example.test/9/Everything/x86_64';

    private string $dir;

    /** @var array<string, string> contenu servi par adresse */
    private array $files = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-dnf-'.bin2hex(random_bytes(4));
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

        // La clé de test remplace celle de Rocky 9 : l'index officiel simulé est signé avec elle
        copy(__DIR__.'/../fixtures/apt/key.asc', $this->dir.'/keys/rocky-9.asc');
        $this->repo(self::BASEOS, 'official');
        $this->files[self::BASEOS.'/repodata/repomd.xml.asc'] = self::fixture('official-repomd.xml.asc');
        $this->files[self::BASEOS.'/Packages/r/rocky-release-9.6-1.el9.noarch.rpm'] = self::fixture('rocky-release.rpm');
        $this->source();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testUnsignedIndexWithSignedPackages(): void
    {
        $this->repo(self::EPEL, 'epel');
        $this->files[self::EPEL.'/Packages/e/epel-release-9-11.el9.noarch.rpm'] = self::fixture('epel-release-9-11.el9.noarch.rpm');

        $byName = [];
        foreach ($this->resolver()->resolve($this->list('epel-release'), $this->context()) as $artifact) {
            $byName[$artifact->getName()] = $artifact;
        }

        // Dépendance (system-release) trouvée dans le dépôt officiel
        self::assertSame(['epel-release', 'rocky-release'], array_keys($byName));
        $metadata = $byName['epel-release']->getMetadata();
        self::assertSame('EPEL', $metadata['repository'] ?? null);
        // Clé publiée par Romulus pour gpgcheck ; aucune pour les paquets de la distribution
        self::assertSame(self::fixture('epel-9.asc'), $metadata['signingKey'] ?? null);
        self::assertArrayNotHasKey('signingKey', $byName['rocky-release']->getMetadata());
        self::assertSame('rocky-9/x86_64/epel-release-9-11.el9.noarch.rpm', $byName['epel-release']->getFilename());
    }

    /** Paquet modifié, index pourtant cohérent : seule sa signature le trahit ; il n'est pas conservé */
    public function testModifiedPackageIsRefusedAndDiscarded(): void
    {
        $this->repo(self::EPEL, 'epel-tampered');
        $this->files[self::EPEL.'/Packages/e/epel-release-9-11.el9.noarch.rpm'] = self::fixture('epel-release-tampered.rpm');

        try {
            $this->resolver()->resolve($this->list('epel-release'), $this->context());
            self::fail('Paquet modifié accepté.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('contenu modifié', $e->getMessage());
        }
        self::assertFalse(static::getContainer()->get(BlobStore::class)->has(hash('sha256', self::fixture('epel-release-tampered.rpm'))));
    }

    private function repo(string $base, string $prefix): void
    {
        $repomd = self::fixture($prefix.'-repomd.xml');
        $href = preg_match('#href="(repodata/[^"]+)"#', $repomd, $m) ? $m[1] : throw new \LogicException($prefix);
        $this->files[$base.'/repodata/repomd.xml'] = $repomd;
        $this->files[$base.'/'.$href] = self::fixture($prefix.'-primary.xml.gz');
    }

    private function source(): void
    {
        $source = new Source(Ecosystem::Rpm);
        $source->update('EPEL', 'https://epel.example.test/9/Everything/$basearch', null, false, true);
        $source->setSystem('rocky-9', null, [], self::fixture('epel-9.asc'));
        $this->em()->persist($source);
        $this->em()->flush();
        static::getContainer()->get(Sources::class)->reset();
    }

    private function resolver(): RpmResolver
    {
        $c = static::getContainer();
        $http = new MockHttpClient(fn (string $method, string $url): MockResponse => isset($this->files[$url])
            ? new MockResponse($this->files[$url])
            : new MockResponse('', ['http_code' => 404]));
        $sources = $c->get(Sources::class);
        $signatures = new Signatures($this->dir.'/keys');
        $store = $c->get(BlobStore::class);

        return new RpmResolver(
            new RpmIndex(new IndexFiles($http, $this->dir.'/cache', $sources), $signatures, $sources),
            new Downloader($http, $store, $sources),
            $c->get(ArtifactCatalog::class),
            $c->get(Blacklist::class),
            new RpmSignature($signatures),
            $store,
        );
    }

    private function list(string $specs): PackageList
    {
        $list = new PackageList('Système', Ecosystem::Rpm);
        $list->setSpecs($specs);
        $list->setOptions(['distribution' => 'rocky-9', 'architectures' => ['x86_64'], 'sections' => ['BaseOS']]);
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
