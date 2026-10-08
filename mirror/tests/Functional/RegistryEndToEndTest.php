<?php

namespace App\Tests\Functional;

use App\Entity\Job;
use App\Entity\Package;
use App\Tests\MirrorWebTestCase;
use App\Tests\Support\BundleBuilder;
use Sas\Bundle\Signer;

/**
 * Parcours complet : un bundle signé contenant un paquet de chaque écosystème est déposé sur la « clé USB »,
 * la clé du collecteur est approuvée, l'import est lancé, puis chaque registre est interrogé comme le
 * feraient npm, pip, composer et docker.
 */
final class RegistryEndToEndTest extends MirrorWebTestCase
{
    private const string INDEX_TYPE = 'application/vnd.oci.image.index.v1+json';
    private const string MANIFEST_TYPE = 'application/vnd.oci.image.manifest.v1+json';

    /** @var array<string, string> contenu des fichiers par rôle */
    private array $data = [];
    /** @var array<string, string> sha256 des fichiers par rôle */
    private array $sha = [];

    protected function setUp(): void
    {
        parent::setUp();

        $keys = Signer::generateKeyPair();
        $builder = new BundleBuilder($this->dir.'/source');

        // npm : une version stable et une préversion (latest doit rester sur la stable)
        foreach (['stable' => '1.2.0', 'beta' => '2.0.0-beta.1'] as $role => $version) {
            $this->add($builder, 'npm-'.$role, random_bytes(1500));
            $builder->artifact('npm', '@sas/demo', $version, null, [$this->sha['npm-'.$role]], [
                'name' => '@sas/demo',
                'version' => $version,
                'dependencies' => ['left-pad' => '^1.3.0'],
                'dist' => [
                    'integrity' => 'sha512-'.base64_encode(hash('sha512', $this->data['npm-'.$role], true)),
                    'shasum' => sha1($this->data['npm-'.$role]),
                    'tarballName' => 'demo-'.$version.'.tgz',
                ],
            ]);
        }

        // PyPI : une roue et une archive source pour le même projet
        foreach (['my_pkg-1.0.0-py3-none-any.whl', 'my_pkg-1.0.0.tar.gz'] as $filename) {
            $this->add($builder, $filename, random_bytes(1200));
            $builder->artifact('pypi', 'my-pkg', '1.0.0', $filename, [$this->sha[$filename]], [
                'displayName' => 'My_Pkg',
                'requiresPython' => '>=3.8,<4',
            ]);
        }

        // Composer
        $this->add($builder, 'composer', random_bytes(900));
        $builder->artifact('composer', 'acme/lib', '1.4.0', null, [$this->sha['composer']], [
            'name' => 'acme/lib',
            'version' => '1.4.0',
            'version_normalized' => '1.4.0.0',
            'type' => 'library',
            'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']],
            'distType' => 'zip',
            'distReference' => 'abc123def',
        ]);

        // Docker : index multi-plateforme -> manifeste linux/amd64 -> configuration + couche
        $this->add($builder, 'layer', random_bytes(4000));
        $this->add($builder, 'config', json_encode(['architecture' => 'amd64', 'os' => 'linux']));
        $this->add($builder, 'manifest', json_encode([
            'schemaVersion' => 2,
            'mediaType' => self::MANIFEST_TYPE,
            'config' => ['mediaType' => 'application/vnd.oci.image.config.v1+json', 'digest' => 'sha256:'.$this->sha['config'], 'size' => \strlen($this->data['config'])],
            'layers' => [['mediaType' => 'application/vnd.oci.image.layer.v1.tar+gzip', 'digest' => 'sha256:'.$this->sha['layer'], 'size' => \strlen($this->data['layer'])]],
        ]));
        $this->add($builder, 'index', json_encode([
            'schemaVersion' => 2,
            'mediaType' => self::INDEX_TYPE,
            'manifests' => [['mediaType' => self::MANIFEST_TYPE, 'digest' => 'sha256:'.$this->sha['manifest'], 'size' => \strlen($this->data['manifest']), 'platform' => ['architecture' => 'amd64', 'os' => 'linux']]],
        ]));
        $builder->artifact('docker', 'library/nginx', '1.27', null, [$this->sha['index'], $this->sha['manifest'], $this->sha['config'], $this->sha['layer']], [
            'registry' => 'docker.io',
            'repository' => 'library/nginx',
            'digest' => 'sha256:'.$this->sha['index'],
            'mediaType' => self::INDEX_TYPE,
            'manifests' => [['digest' => 'sha256:'.$this->sha['manifest'], 'mediaType' => self::MANIFEST_TYPE, 'platform' => 'linux/amd64']],
            'platforms' => ['linux/amd64'],
            'format' => 2,
        ]);

        $name = $builder->write($this->dir.'/inbox', $keys);

        // Approbation de la clé par l'API, comme depuis l'écran « Collecteurs »
        $this->login();
        $this->client->jsonRequest('POST', '/api/keys', ['publicKey' => $keys['publicKey'], 'label' => 'Bureau']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(Signer::fingerprint($keys['publicKey']), $this->json()['fingerprint']);

        $job = $this->import($name);
        self::assertSame(Job::SUCCEEDED, $job->getStatus(), (string) $job->getError());
        self::assertSame(6, $this->countRows(Package::class));
    }

    public function testImportIsRecorded(): void
    {
        $this->client->request('GET', '/api/imports');
        self::assertResponseIsSuccessful();
        $imports = $this->json();
        self::assertCount(1, $imports);
        self::assertSame(1, $imports[0]['sequence']);
        self::assertSame(6, $imports[0]['artifactCount']);
        self::assertSame(['npm' => 2, 'pypi' => 2, 'composer' => 1, 'docker' => 1], $imports[0]['summary']);

        $this->client->request('GET', '/api/overview');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['ecosystems']['pypi']['packages']);
        self::assertSame(2, $this->json()['ecosystems']['pypi']['files']);
    }

    public function testNpmPackumentAndTarball(): void
    {
        $this->client->request('GET', '/npm/@sas/demo');
        self::assertResponseIsSuccessful();
        $packument = $this->json();
        self::assertSame('@sas/demo', $packument['name']);
        self::assertSame(['latest' => '1.2.0'], $packument['dist-tags']);
        self::assertSame(['1.2.0', '2.0.0-beta.1'], array_keys($packument['versions']));
        $dist = $packument['versions']['1.2.0']['dist'];
        self::assertSame('http://localhost/npm/@sas/demo/-/demo-1.2.0.tgz', $dist['tarball']);
        self::assertArrayNotHasKey('tarballName', $dist);
        self::assertSame(sha1($this->data['npm-stable']), $dist['shasum']);
        self::assertSame(['left-pad' => '^1.3.0'], $packument['versions']['1.2.0']['dependencies']);

        $this->client->request('GET', '/npm/@sas/demo/-/demo-1.2.0.tgz');
        self::assertResponseIsSuccessful();
        self::assertSame($this->data['npm-stable'], $this->body());
    }

    public function testNpmScopedNameEncodedSlashAndVersionDocument(): void
    {
        // npm encode la barre des paquets à portée : /@scope%2fnom
        $this->client->request('GET', '/npm/@sas%2fdemo');
        self::assertResponseIsSuccessful();
        self::assertSame('@sas/demo', $this->json()['name']);

        $this->client->request('GET', '/npm/@sas/demo/latest');
        self::assertResponseIsSuccessful();
        self::assertSame('1.2.0', $this->json()['version']);

        $this->client->request('GET', '/npm/@sas/demo/2.0.0-beta.1');
        self::assertResponseIsSuccessful();
        self::assertSame('http://localhost/npm/@sas/demo/-/demo-2.0.0-beta.1.tgz', $this->json()['dist']['tarball']);
    }

    public function testNpmUnknownPackageIs404(): void
    {
        $this->client->request('GET', '/npm/inconnu');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/npm/@sas/demo/9.9.9');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/npm/@sas/demo/-/demo-9.9.9.tgz');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPypiSimpleIndexHtml(): void
    {
        $this->client->request('GET', '/pypi/simple/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<a href="my-pkg/">my-pkg</a>', $this->body());

        $this->client->request('GET', '/pypi/simple/my-pkg/');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=utf-8');
        $html = $this->body();
        foreach (['my_pkg-1.0.0-py3-none-any.whl', 'my_pkg-1.0.0.tar.gz'] as $filename) {
            self::assertStringContainsString(
                sprintf('href="http://localhost/pypi/files/%1$s/%2$s#sha256=%1$s"', $this->sha[$filename], $filename),
                $html,
            );
        }
        // « < » et « > » doivent être échappés dans l'attribut
        self::assertStringContainsString('data-requires-python="&gt;=3.8,&lt;4"', $html);
        self::assertStringNotContainsString('data-requires-python=">=', $html);
    }

    public function testPypiNonNormalizedNameRedirects(): void
    {
        $this->client->request('GET', '/pypi/simple/My_Pkg/');
        self::assertResponseStatusCodeSame(301);
        self::assertResponseHeaderSame('Location', '/pypi/simple/my-pkg/');

        $this->client->request('GET', '/pypi/simple/absent/');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPypiJsonApi(): void
    {
        $this->client->request('GET', '/pypi/simple/', [], [], ['HTTP_ACCEPT' => 'application/vnd.pypi.simple.v1+json']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.pypi.simple.v1+json');
        self::assertSame([['name' => 'my-pkg']], $this->json()['projects']);

        $this->client->request('GET', '/pypi/simple/my-pkg/', [], [], ['HTTP_ACCEPT' => 'application/vnd.pypi.simple.v1+json']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.pypi.simple.v1+json');
        $project = $this->json();
        self::assertSame('my-pkg', $project['name']);
        self::assertSame(['1.0.0'], $project['versions']);
        self::assertCount(2, $project['files']);
        $file = $project['files'][0];
        self::assertSame('my_pkg-1.0.0-py3-none-any.whl', $file['filename']);
        self::assertSame(['sha256' => $this->sha[$file['filename']]], $file['hashes']);
        self::assertSame('>=3.8,<4', $file['requires-python']);
        self::assertSame(1200, $file['size']);
    }

    public function testPypiFileDownload(): void
    {
        $filename = 'my_pkg-1.0.0.tar.gz';
        $this->client->request('GET', sprintf('/pypi/files/%s/%s', $this->sha[$filename], $filename));
        self::assertResponseIsSuccessful();
        self::assertSame($this->data[$filename], $this->body());

        $this->client->request('GET', sprintf('/pypi/files/%s/x.whl', str_repeat('0', 64)));
        self::assertResponseStatusCodeSame(404);
    }

    public function testComposerRepository(): void
    {
        $this->client->request('GET', '/composer/packages.json');
        self::assertResponseIsSuccessful();
        $root = $this->json();
        self::assertSame('/composer/p2/%package%.json', $root['metadata-url']);
        self::assertSame(['acme/lib'], $root['available-packages']);

        $this->client->request('GET', '/composer/p2/acme/lib.json');
        self::assertResponseIsSuccessful();
        $versions = $this->json()['packages']['acme/lib'];
        self::assertCount(1, $versions);
        self::assertSame('1.4.0', $versions[0]['version']);
        self::assertSame(['psr-4' => ['Acme\\Lib\\' => 'src/']], $versions[0]['autoload']);
        self::assertArrayNotHasKey('distType', $versions[0]);
        self::assertArrayNotHasKey('distReference', $versions[0]);
        self::assertSame([
            'type' => 'zip',
            'url' => sprintf('http://localhost/composer/dist/%s/acme-lib-1.4.0.zip', $this->sha['composer']),
            'reference' => 'abc123def',
            'shasum' => '',
        ], $versions[0]['dist']);

        $this->client->request('GET', parse_url($versions[0]['dist']['url'], \PHP_URL_PATH));
        self::assertResponseIsSuccessful();
        self::assertSame($this->data['composer'], $this->body());
    }

    public function testComposerDevFileAndUnknownPackage(): void
    {
        $this->client->request('GET', '/composer/p2/acme/lib~dev.json');
        self::assertResponseIsSuccessful();
        self::assertSame(['packages' => ['acme/lib' => []]], $this->json());

        $this->client->request('GET', '/composer/p2/acme/absent.json');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDockerApiRoot(): void
    {
        $this->client->request('GET', '/v2/');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Docker-Distribution-API-Version', 'registry/2.0');

        $this->client->request('GET', '/v2/_catalog');
        self::assertSame(['repositories' => ['library/nginx']], $this->json());
    }

    public function testDockerManifestByTagAndDigest(): void
    {
        $this->client->request('GET', '/v2/library/nginx/manifests/1.27');
        self::assertResponseIsSuccessful();
        self::assertSame($this->data['index'], $this->body());
        self::assertResponseHeaderSame('Content-Type', self::INDEX_TYPE);
        self::assertResponseHeaderSame('Docker-Content-Digest', 'sha256:'.$this->sha['index']);
        // Une étiquette peut être déplacée : pas de cache immuable
        self::assertStringContainsString('no-cache', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->client->request('GET', '/v2/library/nginx/manifests/sha256:'.$this->sha['manifest']);
        self::assertResponseIsSuccessful();
        self::assertSame($this->data['manifest'], $this->body());
        self::assertResponseHeaderSame('Content-Type', self::MANIFEST_TYPE);
        self::assertResponseHeaderSame('Docker-Content-Digest', 'sha256:'.$this->sha['manifest']);

        // L'index lui-même est accessible par son empreinte
        $this->client->request('GET', '/v2/library/nginx/manifests/sha256:'.$this->sha['index']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', self::INDEX_TYPE);
    }

    public function testDockerHeadAndShortName(): void
    {
        $this->client->request('HEAD', '/v2/library/nginx/manifests/1.27');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Docker-Content-Digest', 'sha256:'.$this->sha['index']);
        self::assertResponseHeaderSame('Content-Type', self::INDEX_TYPE);

        // « nginx » désigne « library/nginx », comme sur Docker Hub
        $this->client->request('GET', '/v2/nginx/manifests/1.27');
        self::assertResponseIsSuccessful();
        self::assertSame($this->data['index'], $this->body());

        $this->client->request('GET', '/v2/nginx/tags/list');
        self::assertResponseIsSuccessful();
        self::assertSame(['name' => 'nginx', 'tags' => ['1.27']], $this->json());
    }

    public function testDockerBlobs(): void
    {
        foreach (['config', 'layer'] as $role) {
            $this->client->request('GET', '/v2/library/nginx/blobs/sha256:'.$this->sha[$role]);
            self::assertResponseIsSuccessful();
            self::assertSame($this->data[$role], $this->body());
            self::assertResponseHeaderSame('Docker-Content-Digest', 'sha256:'.$this->sha[$role]);
        }

        $this->client->request('GET', '/v2/library/nginx/blobs/sha256:'.str_repeat('a', 64));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('BLOB_UNKNOWN', $this->json()['errors'][0]['code']);
    }

    public function testDockerUnknownAndReadOnly(): void
    {
        $this->client->request('GET', '/v2/library/absent/manifests/latest');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('NAME_UNKNOWN', $this->json()['errors'][0]['code']);

        $this->client->request('GET', '/v2/library/nginx/manifests/9.9');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('MANIFEST_UNKNOWN', $this->json()['errors'][0]['code']);

        $this->client->request('GET', '/v2/library/nginx/manifests/sha256:'.str_repeat('b', 64));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('MANIFEST_UNKNOWN', $this->json()['errors'][0]['code']);

        $this->client->request('GET', '/v2/absent/tags/list');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('NAME_UNKNOWN', $this->json()['errors'][0]['code']);

        $this->client->request('PUT', '/v2/library/nginx/manifests/1.28', [], [], ['CONTENT_TYPE' => self::MANIFEST_TYPE], '{}');
        self::assertResponseStatusCodeSame(405);
        self::assertSame('UNSUPPORTED', $this->json()['errors'][0]['code']);
        self::assertResponseHeaderSame('Docker-Distribution-API-Version', 'registry/2.0');
    }

    public function testReimportIsIdempotent(): void
    {
        // Le même bundle réimporté ne crée ni doublon de paquet ni nouvel historique
        $name = array_key_first(\Sas\Bundle\BundleFiles::discover($this->dir.'/inbox'));
        $job = $this->import($name);
        self::assertSame(Job::SUCCEEDED, $job->getStatus(), (string) $job->getError());
        self::assertSame(6, $this->countRows(Package::class));
        self::assertSame(1, $this->countRows(\App\Entity\ImportedBundle::class));
    }

    private function add(BundleBuilder $builder, string $role, string $data): void
    {
        $this->data[$role] = $data;
        $this->sha[$role] = $builder->blob($data);
    }
}
