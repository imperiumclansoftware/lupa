<?php

namespace App\Tests\Functional;

use App\Entity\Artifact;
use App\Entity\Source;
use App\Source\Sources;
use App\Entity\Blob;
use App\Entity\Export;
use App\Entity\Job;
use App\Entity\User;
use App\Identity\CollectorIdentity;
use App\Resolver\ArtifactCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Sas\Bundle\BlobStore;
use Sas\Bundle\BundleFiles;
use Sas\Bundle\BundleReader;
use Sas\Bundle\Ecosystem;
use Sas\Bundle\Manifest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Liste noire (exclusion des exports, transmission dans le manifeste signé) et dépôts sources privés
 * (secrets chiffrés jamais renvoyés, configuration générée pour npm, pip, Composer, Docker).
 */
final class BlacklistAndSourcesTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-collector-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/storage', 0o777, true);
        self::setEnv('STORAGE_DIR', $this->dir.'/storage');
        // Dossier d'export créé par l'export lui-même
        self::setEnv('EXPORT_DIR', $this->dir.'/usb');

        $this->client = static::createClient();
        $em = $this->em();
        // Clés étrangères suspendues : SQLite refuse sinon de supprimer une table encore référencée
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = OFF');
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');

        $user = new User('admin');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret-test'));
        $em->persist($user);
        $em->flush();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testBlacklistIsExcludedFromExportsAndTravelsInTheManifest(): void
    {
        $a = $this->blob('lodash 4.17.20');
        $b = $this->blob('lodash 4.17.21');
        $this->record(Ecosystem::Npm, 'lodash', '4.17.20', null, [$a]);
        $this->record(Ecosystem::Npm, 'lodash', '4.17.21', null, [$b]);

        // Versions précises uniquement
        foreach (['^4.17', '4.x', 'latest', '>=1.0', '1.0 || 2.0'] as $range) {
            $this->client->jsonRequest('POST', '/api/blacklist', ['ecosystem' => 'npm', 'name' => 'lodash', 'version' => $range]);
            self::assertResponseStatusCodeSame(422, $range);
        }
        $this->client->jsonRequest('POST', '/api/blacklist', ['ecosystem' => 'npm', 'name' => 'lod*', 'version' => '1.0.0']);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('POST', '/api/blacklist', ['ecosystem' => 'npm', 'name' => 'Lodash', 'version' => '4.17.20', 'reason' => 'CVE-2021-23337']);
        self::assertResponseStatusCodeSame(201);
        // Doublon (même version, autre écriture du nom) : entrée existante
        $this->client->jsonRequest('POST', '/api/blacklist', ['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.20']);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/blacklist', ['ecosystem' => 'pypi', 'name' => 'Django', 'version' => '4.2.0', 'reason' => 'non validé']);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/blacklist');
        $list = $this->json();
        self::assertSame(2, $list['revision']);
        self::assertTrue($list['pending']);
        self::assertCount(2, $list['items']);
        $lodash = array_values(array_filter($list['items'], static fn ($i) => 'lodash' === $i['name']))[0];
        self::assertSame(1, $lodash['collected']);

        $this->client->request('GET', '/api/exports');
        self::assertSame(1, $this->json()['pending']['artifacts']);
        $this->client->request('GET', '/api/artifacts?q=lodash');
        $flags = array_column($this->json()['items'], 'blacklisted', 'version');
        self::assertSame('CVE-2021-23337', $flags['4.17.20']['reason']);
        self::assertNull($flags['4.17.21']);

        // Export : la version interdite reste au collecteur, la liste part dans le manifeste
        $manifest = $this->export(false, 1);
        self::assertSame(['4.17.21'], array_column($manifest->artifacts, 'version'));
        self::assertSame([$b['sha256']], array_column($manifest->blobs, 'sha256'));
        self::assertSame(2, $manifest->blacklist['revision']);
        self::assertEqualsCanonicalizing(
            [['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.20', 'reason' => 'CVE-2021-23337'], ['ecosystem' => 'pypi', 'name' => 'django', 'version' => '4.2.0', 'reason' => 'non validé']],
            $manifest->blacklist['entries'],
        );

        // Rien de nouveau, liste inchangée : pas d'export
        $this->client->jsonRequest('POST', '/api/exports', ['full' => false]);
        self::assertResponseStatusCodeSame(422);

        // Retrait d'une entrée : un bundle sans paquet transporte la nouvelle révision
        $django = array_values(array_filter($list['items'], static fn ($i) => 'django' === $i['name']))[0];
        $this->client->request('DELETE', '/api/blacklist/'.$django['id']);
        self::assertResponseStatusCodeSame(204);
        $manifest = $this->export(false, 2);
        self::assertSame([], $manifest->artifacts);
        self::assertSame(3, $manifest->blacklist['revision']);
        self::assertSame(['lodash'], array_column($manifest->blacklist['entries'], 'name'));
        $this->client->request('GET', '/api/blacklist');
        self::assertFalse($this->json()['pending']);
    }

    public function testSourcesKeepSecretsAndGenerateToolConfiguration(): void
    {
        // npm : une portée, ou le remplacement du registre public
        $this->client->jsonRequest('POST', '/api/sources', ['ecosystem' => 'npm', 'label' => 'Sans portée', 'url' => 'https://npm.acme.test']);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/sources', ['ecosystem' => 'npm', 'label' => 'Verdaccio', 'url' => 'https://npm.acme.test/', 'scope' => '@Acme', 'authType' => 'token', 'secret' => 'npm-token-123']);
        self::assertResponseStatusCodeSame(201);
        $npm = $this->json();
        self::assertSame('@acme', $npm['scope']);
        self::assertTrue($npm['hasSecret']);
        self::assertArrayNotHasKey('secret', $npm);
        self::assertStringNotContainsString('npm-token-123', (string) $this->client->getInternalResponse()->getContent());

        $this->client->jsonRequest('POST', '/api/sources', ['ecosystem' => 'pypi', 'label' => 'devpi', 'url' => 'https://pypi.acme.test/root/prod']);
        self::assertResponseStatusCodeSame(422, 'adresse sans /simple');
        $this->client->jsonRequest('POST', '/api/sources', ['ecosystem' => 'pypi', 'label' => 'devpi', 'url' => 'https://pypi.acme.test/root/prod/simple', 'authType' => 'basic', 'username' => 'ci', 'secret' => 'p@ss']);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/sources', ['ecosystem' => 'composer', 'label' => 'Satis', 'url' => 'http://satis.acme.test', 'authType' => 'token', 'secret' => 'glpat-x', 'replacesDefault' => true]);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/sources', ['ecosystem' => 'composer', 'label' => 'Autre', 'url' => 'https://repo.acme.test', 'replacesDefault' => true]);
        self::assertResponseStatusCodeSame(422, 'un seul remplaçant');
        $this->client->jsonRequest('POST', '/api/sources', ['ecosystem' => 'docker', 'label' => 'Harbor', 'url' => 'harbor.acme.test:5000', 'authType' => 'basic', 'username' => 'robot', 'secret' => 'hb-secret']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('https://harbor.acme.test:5000', $this->json()['url']);

        // Secret stocké chiffré
        $stored = $this->em()->getConnection()->fetchFirstColumn('SELECT secret FROM source WHERE secret IS NOT NULL');
        self::assertCount(4, $stored);
        foreach ($stored as $secret) {
            self::assertStringStartsWith('v1:', $secret);
            self::assertStringNotContainsString('npm-token', $secret);
        }

        // Modification sans secret : secret conservé
        $this->client->jsonRequest('PUT', '/api/sources/'.$npm['id'], ['ecosystem' => 'npm', 'label' => 'Verdaccio interne', 'url' => 'https://npm.acme.test', 'scope' => '@acme', 'authType' => 'token']);
        self::assertResponseIsSuccessful();

        $sources = static::getContainer()->get(Sources::class);
        $sources->reset();
        self::assertSame("registry=https://registry.npmjs.org/\n@acme:registry=https://npm.acme.test/\n//npm.acme.test/:_authToken=npm-token-123\n", $sources->npmrc('https://registry.npmjs.org'));
        self::assertSame('https://npm.acme.test', $sources->npmRegistry('@acme/ui', 'https://registry.npmjs.org'));
        self::assertSame('https://registry.npmjs.org', $sources->npmRegistry('lodash', 'https://registry.npmjs.org/'));
        self::assertSame(
            ['--index-url', 'https://pypi.org/simple', '--extra-index-url', 'https://ci:p%40ss@pypi.acme.test/root/prod/simple/'],
            $sources->pipIndexOptions('https://pypi.org'),
        );
        self::assertSame([['type' => 'composer', 'url' => 'http://satis.acme.test'], ['packagist.org' => false]], $sources->composerRepositories());
        self::assertTrue($sources->composerInsecure());
        self::assertSame(['github-oauth' => ['github.com' => 'gh'], 'bearer' => ['satis.acme.test' => 'glpat-x']], $sources->composerAuth(['github-oauth' => ['github.com' => 'gh']]));
        self::assertSame(['robot', 'hb-secret'], $sources->dockerCredentials('harbor.acme.test:5000'));
        self::assertNull($sources->dockerCredentials('ghcr.io'));
        self::assertSame(['auth_bearer' => 'npm-token-123'], $sources->httpOptions('https://npm.acme.test/@acme/ui/-/ui-1.0.0.tgz'));
        self::assertSame(['auth_basic' => ['ci', 'p@ss']], $sources->httpOptions('https://pypi.acme.test/root/prod/+f/abc/pkg-1.0-py3-none-any.whl'));
        self::assertSame([], $sources->httpOptions('https://registry.npmjs.org/lodash/-/lodash-4.17.21.tgz'));

        // Désactivé : ignoré
        $this->client->jsonRequest('PUT', '/api/sources/'.$npm['id'], ['ecosystem' => 'npm', 'label' => 'Verdaccio interne', 'url' => 'https://npm.acme.test', 'scope' => '@acme', 'authType' => 'token', 'enabled' => false]);
        $sources->reset();
        self::assertNull($sources->npmrc('https://registry.npmjs.org'));

        $this->client->request('DELETE', '/api/sources/'.$npm['id']);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/sources');
        self::assertCount(3, $this->json());
    }

    public function testBlacklistedVersionsAreNotDownloaded(): void
    {
        $blacklist = static::getContainer()->get(\App\Blacklist\Blacklist::class);
        $blacklist->add(Ecosystem::Docker, 'nginx', '1.25.3', 'image non conforme');
        $blacklist->add(Ecosystem::Composer, 'monolog/monolog', '3.5.0', '');
        $context = new \App\Job\JobContext($this->em()->getConnection(), 0);

        self::assertTrue($blacklist->blocks(Ecosystem::Docker, 'library/nginx', '1.25.3', $context));
        self::assertFalse($blacklist->blocks(Ecosystem::Docker, 'library/nginx', '1.25.4', $context));
        self::assertTrue($blacklist->blocks(Ecosystem::Composer, 'Monolog/Monolog', 'v3.5.0', $context));
        self::assertSame(['library/nginx:1.25.3', 'Monolog/Monolog v3.5.0'], $blacklist->takeBlocked());
        self::assertSame([], $blacklist->takeBlocked());
    }

    /** Lance un export, vérifie la tâche et relit le bundle écrit (signature comprise). */
    private function export(bool $full, int $sequence): Manifest
    {
        $this->client->jsonRequest('POST', '/api/exports', ['full' => $full]);
        self::assertResponseStatusCodeSame(202);
        $em = $this->em();
        $em->clear();
        $job = $em->find(Job::class, $this->json()['id']);
        self::assertSame(Job::SUCCEEDED, $job->getStatus(), (string) $job->getError());

        $identity = static::getContainer()->get(CollectorIdentity::class);
        $base = BundleFiles::baseName($identity->name(), $sequence);
        $file = $this->dir.'/usb/'.$base.'.tar';
        self::assertFileExists($file);

        $export = $em->getRepository(Export::class)->findOneBy(['sequence' => $sequence]);
        self::assertSame([$base.'.tar'], $export->getFiles());

        $reader = new BundleReader([$file]);
        self::assertTrue($reader->verifySignature($identity->publicKey()));
        $manifest = $reader->manifest();
        self::assertSame($sequence, $manifest->sequence);
        self::assertSame($export->getUuid(), $manifest->id);

        // Les fichiers transportés sont bien ceux annoncés, empreintes vérifiées
        $target = new BlobStore($this->dir.'/check-'.$sequence);
        self::assertEqualsCanonicalizing(array_column($manifest->blobs, 'sha256'), $reader->extractBlobs($target));

        // Téléchargement par le navigateur (Exports › Historique) : fichier identique
        $this->client->request('GET', sprintf('/api/exports/%d/files/%s.tar', $export->getId(), $base));
        $downloaded = $this->client->getInternalResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/x-tar');
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(file_get_contents($file), $downloaded);

        return $manifest;
    }

    /** @return array{sha256: string, size: int} */
    private function blob(string $data): array
    {
        return static::getContainer()->get(BlobStore::class)->putString($data);
    }

    /** @param list<array{sha256: string, size: int}> $blobs */
    private function record(Ecosystem $ecosystem, string $name, string $version, ?string $filename, array $blobs): void
    {
        $catalog = static::getContainer()->get(ArtifactCatalog::class);
        $catalog->record($ecosystem, $name, $version, $filename, $blobs, ['name' => $name]);
        $catalog->flush();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /** @return array<mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getInternalResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function setEnv(string $name, string $value): void
    {
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }
}
