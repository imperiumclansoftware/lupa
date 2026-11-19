<?php

namespace App\Tests\Functional;

use App\Blacklist\Blacklist;
use App\Entity\Artifact;
use App\Entity\PackageList;
use App\Http\Downloader;
use App\Job\JobContext;
use App\Resolver\ArtifactCatalog;
use App\Resolver\Vscode\Gallery;
use App\Resolver\Vscode\GalleryVersion;
use App\Resolver\VscodeResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Sas\Bundle\BlobStore;
use Sas\Bundle\Ecosystem;
use Sas\Bundle\IntegrityException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\NilUuid;

/**
 * Extensions VS Code, contre une galerie simulée qui parle le protocole d'Open VSX et du Marketplace.
 *
 *  acme.pack 1.0.0 (2.0.0 en préversion) : pack de acme.tool et acme.native
 *  acme.tool 3.0.0 (VS Code ^1.99) et 2.5.0 (^1.90), dépend de vscode.git (intégrée)
 *  acme.native 1.2.0 : constructions linux-x64, win32-x64, darwin-arm64
 *  acme.msonly 4.0.0 : seulement sur le Marketplace (somme publiée en propriété)
 */
final class VscodeResolverTest extends KernelTestCase
{
    private string $dir;

    /** @var array<string, string> contenu servi par adresse */
    private array $files = [];

    /** @var array<string, string> sommes .sha256 publiées (Open VSX), par adresse de VSIX */
    private array $sums = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-vscode-'.bin2hex(random_bytes(4));
        $_ENV['STORAGE_DIR'] = $_SERVER['STORAGE_DIR'] = $this->dir;
        putenv('STORAGE_DIR='.$this->dir);
        self::bootKernel();
        $em = $this->em();
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = OFF');
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testResolvesPacksPlatformsEnginesAndSources(): void
    {
        $list = $this->list("acme.pack\nacme.msonly@4.0.0", [
            'platforms' => ['linux-x64', 'win32-x64'], 'source' => 'openvsx+marketplace', 'vscodeVersion' => '1.95',
        ]);
        $resolver = $this->resolver();
        self::assertSame([], $resolver->validate($list));
        $artifacts = $resolver->resolve($list, new JobContext($this->em()->getConnection(), new NilUuid()));

        $found = array_map(static fn (Artifact $a) => $a->getName().' '.$a->getVersion().' '.$a->getFilename(), $artifacts);
        sort($found);
        self::assertSame([
            'acme.msonly 4.0.0 acme.msonly-4.0.0.vsix',
            'acme.native 1.2.0 acme.native-1.2.0@linux-x64.vsix',
            'acme.native 1.2.0 acme.native-1.2.0@win32-x64.vsix',
            'acme.pack 1.0.0 acme.pack-1.0.0.vsix',      // pas la préversion 2.0.0
            'acme.tool 2.5.0 acme.tool-2.5.0.vsix',      // 3.0.0 exige VS Code 1.99
        ], $found);

        $pack = $this->artifact($artifacts, 'acme.pack');
        self::assertSame('openvsx', $pack->getMetadata()['source']);
        self::assertSame(['acme.tool', 'acme.native'], $pack->getMetadata()['extensionPack']);
        // README collecté avec le VSIX, pour la galerie de Romulus
        $assets = (array) $pack->getMetadata()['assets'];
        self::assertSame(hash('sha256', '# Pack'), $assets['Microsoft.VisualStudio.Services.Content.Details']);
        self::assertCount(2, $pack->getBlobs());
        self::assertSame(hash('sha256', 'vsix acme.pack 1.0.0 universal'), $pack->getBlobs()[0]);

        // Contrat avec Romulus (mirror/src/Registry/VscodeGallery.php) : clés lues par la marketplace
        $keys = array_keys($pack->getMetadata());
        sort($keys);
        self::assertSame(['assets', 'categories', 'dependencies', 'description', 'displayName', 'engine', 'extensionId', 'extensionPack', 'lastUpdated', 'name', 'preRelease',
            'properties', 'publisher', 'publisherDisplayName', 'publisherId', 'releaseDate', 'source', 'tags', 'targetPlatform'], $keys);

        $msonly = $this->artifact($artifacts, 'acme.msonly');
        self::assertSame('marketplace', $msonly->getMetadata()['source']);
        self::assertSame('ACME Corp', $msonly->getMetadata()['publisherDisplayName']);
    }

    public function testWrongPublishedChecksumIsRefused(): void
    {
        $resolver = $this->resolver(['https://ovsx.test/files/acme.tool-2.5.0.vsix' => str_repeat('0', 64)]);
        $this->expectException(IntegrityException::class);
        $resolver->resolve($this->list('acme.tool@2.5.0', []), new JobContext($this->em()->getConnection(), new NilUuid()));
    }

    public function testUnknownExtensionNamesItsSources(): void
    {
        $this->expectExceptionMessage('Extension introuvable sur Open VSX : acme.absent');
        $this->resolver()->resolve($this->list('acme.absent', []), new JobContext($this->em()->getConnection(), new NilUuid()));
    }

    public function testValidation(): void
    {
        $errors = $this->resolver()->validate($this->list("acme\nacme.ok >=x", ['platforms' => ['amiga-68k'], 'source' => 'ailleurs', 'vscodeVersion' => 'récente']));
        self::assertCount(5, $errors, implode("\n", $errors));
    }

    public function testSpecsAndExtensionsJson(): void
    {
        self::assertSame(['ms-python.python', '*'], VscodeResolver::parseSpec('ms-python.python'));
        self::assertSame(['ms-python.python', '2024.2.0'], VscodeResolver::parseSpec('MS-Python.Python@2024.2.0'));
        self::assertSame(['redhat.vscode-yaml', '>=1.14'], VscodeResolver::parseSpec('redhat.vscode-yaml >=1.14'));

        $json = <<<'JSON'
            {
              // Extensions recommandées pour ce dépôt
              "recommendations": [
                "dbaeumer.vscode-eslint", /* linter */
                "esbenp.prettier-vscode",
                "redhat.vscode-yaml", // https://github.com/redhat-developer/vscode-yaml
              ],
              "unwantedRecommendations": ["ms-vscode.vscode-typescript-tslint-plugin"]
            }
            JSON;
        self::assertSame(['dbaeumer.vscode-eslint', 'esbenp.prettier-vscode', 'redhat.vscode-yaml'], VscodeResolver::parseExtensionsJson($json));
        $this->expectException(\InvalidArgumentException::class);
        VscodeResolver::parseExtensionsJson('{"extensions": []}');
    }

    public function testEngineCompatibility(): void
    {
        self::assertTrue(VscodeResolver::engineAccepts('^1.91.0', '1.95'));
        self::assertFalse(VscodeResolver::engineAccepts('^1.99.0', '1.95.3'));
        self::assertTrue(VscodeResolver::engineAccepts('>=1.60.0', '1.95'));
        self::assertTrue(VscodeResolver::engineAccepts('*', '1.95'));
        self::assertTrue(VscodeResolver::engineAccepts('^1.96.0-insider', '1.96'));
    }

    public function testChoosePrefersUniversalBuildAndSkipsPreReleases(): void
    {
        $v = static fn (string $version, string $platform, bool $pre = false) => new GalleryVersion($version, $platform, $pre, '*', [], [], null, [], []);
        $versions = [$v('2.0.0', 'universal', true), $v('1.1.0', 'linux-x64'), $v('1.1.0', 'darwin-arm64'), $v('1.0.0', 'universal')];

        $chosen = VscodeResolver::choose($versions, '*', ['linux-x64'], '', false);
        self::assertSame(['1.1.0 linux-x64'], array_map(static fn (GalleryVersion $b) => $b->version.' '.$b->targetPlatform, $chosen ?? []));
        self::assertSame('2.0.0', VscodeResolver::choose($versions, '*', ['linux-x64'], '', true)[0]->version ?? null);
        self::assertSame('1.0.0', VscodeResolver::choose($versions, '*', ['win32-x64'], '', false)[0]->version ?? null);
        self::assertNull(VscodeResolver::choose($versions, '>=3', ['linux-x64'], '', false));
    }

    /** Comme VS Code : la construction propre à la plateforme l'emporte sur l'universelle de même version. */
    public function testPlatformBuildIsPreferredOverUniversal(): void
    {
        $v = static fn (string $version, string $platform) => new GalleryVersion($version, $platform, false, '*', [], [], null, [], []);
        $versions = [$v('0.3.3121', 'universal'), $v('0.3.3121', 'linux-x64'), $v('0.3.3121', 'win32-x64'), $v('0.3.3121', 'darwin-arm64')];
        $describe = static fn (?array $builds) => array_map(static fn (GalleryVersion $b) => $b->targetPlatform, $builds ?? []);

        self::assertSame(['linux-x64', 'win32-x64'], $describe(VscodeResolver::choose($versions, '*', ['linux-x64', 'win32-x64'], '', false)));
        // Plateforme sans construction propre : l'universelle
        self::assertSame(['linux-x64', 'universal'], $describe(VscodeResolver::choose($versions, '*', ['linux-x64', 'alpine-x64'], '', false)));
    }

    /** @param array<string, string> $wrongSums */
    private function resolver(array $wrongSums = []): VscodeResolver
    {
        $this->publish();
        foreach ($wrongSums as $url => $sum) {
            $this->sums[$url] = $sum;
        }
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if ('POST' === $method) {
                $body = json_decode((string) ($options['body'] ?? '{}'), true);
                $id = strtolower((string) ($body['filters'][0]['criteria'][0]['value'] ?? ''));

                return new MockResponse((string) json_encode(['results' => [['extensions' => array_values(array_filter([$this->extension($url, $id)]))]]]));
            }
            if (str_ends_with($url, '.sha256')) {
                $vsix = substr($url, 0, -7).'.vsix';

                return isset($this->sums[$vsix]) ? new MockResponse($this->sums[$vsix].'  file.vsix') : new MockResponse('', ['http_code' => 404]);
            }

            return isset($this->files[$url]) ? new MockResponse($this->files[$url]) : new MockResponse('', ['http_code' => 404]);
        });
        $container = static::getContainer();
        $downloader = new Downloader($http, $container->get(BlobStore::class));

        return new VscodeResolver(new Gallery($downloader, 'https://ovsx.test', 'https://mp.test'), $downloader, $container->get(ArtifactCatalog::class), $container->get(Blacklist::class));
    }

    /** Extensions publiées par la galerie simulée */
    private function publish(): void
    {
        $this->files = $this->sums = [];
    }

    /** @return array<string, mixed>|null réponse « extensionquery » pour une extension */
    private function extension(string $url, string $id): ?array
    {
        $marketplace = str_starts_with($url, 'https://mp.test');
        $catalog = [
            'acme.pack' => [false, [['2.0.0', 'universal', true, '*', [], []], ['1.0.0', 'universal', false, '^1.80.0', [], ['acme.tool', 'acme.native']]]],
            'acme.tool' => [false, [['3.0.0', 'universal', false, '^1.99.0', ['vscode.git'], []], ['2.5.0', 'universal', false, '^1.90.0', ['vscode.git'], []]]],
            'acme.native' => [false, [['1.2.0', 'linux-x64', false, '*', [], []], ['1.2.0', 'win32-x64', false, '*', [], []], ['1.2.0', 'darwin-arm64', false, '*', [], []]]],
            'acme.msonly' => [true, [['4.0.0', 'universal', false, '*', [], []]]],
        ];
        if (!isset($catalog[$id]) || $catalog[$id][0] !== $marketplace) {
            return null;
        }
        [$publisher, $name] = explode('.', $id, 2);
        $versions = [];
        foreach ($catalog[$id][1] as [$version, $platform, $pre, $engine, $deps, $pack]) {
            $vsix = \sprintf('https://%s/files/%s-%s%s.vsix', $marketplace ? 'mp.test' : 'ovsx.test', $id, $version, 'universal' === $platform ? '' : '@'.$platform);
            $content = \sprintf('vsix %s %s %s', $id, $version, $platform);
            $this->files[$vsix] = $content;
            $this->sums[$vsix] ??= hash('sha256', $content);
            $files = [['assetType' => 'Microsoft.VisualStudio.Services.VSIXPackage', 'source' => $vsix]];
            if ('acme.pack' === $id) {
                $this->files['https://ovsx.test/files/readme.md'] = '# Pack';
                $files[] = ['assetType' => 'Microsoft.VisualStudio.Services.Content.Details', 'source' => 'https://ovsx.test/files/readme.md'];
            }
            $properties = [
                ['key' => 'Microsoft.VisualStudio.Code.Engine', 'value' => $engine],
                ['key' => 'Microsoft.VisualStudio.Code.ExtensionDependencies', 'value' => implode(',', $deps)],
                ['key' => 'Microsoft.VisualStudio.Code.ExtensionPack', 'value' => implode(',', $pack)],
                ['key' => 'Microsoft.VisualStudio.Code.PreRelease', 'value' => json_encode($pre)],
            ];
            if ($marketplace) {
                $properties[] = ['key' => 'Microsoft.VisualStudio.Services.VsixSha256', 'value' => hash('sha256', $content)];
                unset($this->sums[$vsix]);
            }
            $versions[] = ['version' => $version, 'targetPlatform' => $platform, 'files' => $files, 'properties' => $properties, 'lastUpdated' => '2026-11-01T00:00:00Z'];
        }

        return [
            'extensionId' => '00000000-0000-4000-8000-'.substr(hash('sha256', $id), 0, 12),
            'extensionName' => $name,
            'displayName' => ucfirst($name),
            'shortDescription' => 'Extension '.$name,
            'publisher' => ['publisherId' => '11111111-0000-4000-8000-000000000000', 'publisherName' => $publisher, 'displayName' => 'ACME Corp'],
            'categories' => ['Other'],
            'tags' => [],
            'versions' => $versions,
        ];
    }

    /** @param array<string, mixed> $options */
    private function list(string $specs, array $options): PackageList
    {
        $list = new PackageList('Extensions', Ecosystem::Vscode);
        $list->setSpecs($specs);
        $list->setOptions($options);

        return $list;
    }

    /** @param list<Artifact> $artifacts */
    private function artifact(array $artifacts, string $name): Artifact
    {
        foreach ($artifacts as $artifact) {
            if ($name === $artifact->getName()) {
                return $artifact;
            }
        }
        self::fail($name.' absent');
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
