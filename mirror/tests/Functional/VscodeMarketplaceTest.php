<?php

namespace App\Tests\Functional;

use App\Entity\Job;
use App\Tests\MirrorWebTestCase;
use App\Tests\Support\BundleBuilder;
use Sas\Bundle\Signer;

/**
 * Marketplace VS Code de Romulus : protocole de galerie (celui d'Open VSX et du Marketplace), tel que
 * l'interrogent VS Code, VSCodium et code-server.
 *
 *  redhat.vscode-yaml 1.14.0 et 1.15.0 (universelles, README et icône collectés à part)
 *  rust-lang.rust-analyzer 0.3.2000 : linux-x64 et win32-x64
 */
final class VscodeMarketplaceTest extends MirrorWebTestCase
{
    private const string README = '# YAML pour VS Code';

    /** @var array<string, string> */
    private array $vsix = [];

    protected function setUp(): void
    {
        parent::setUp();
        $keys = Signer::generateKeyPair();
        $b = new BundleBuilder($this->dir.'/source');
        $readme = $b->blob(self::README);
        $icon = $b->blob("\x89PNG\r\n\x1a\n icône");
        foreach (['1.14.0', '1.15.0'] as $version) {
            $this->vsix[$version] = $b->blob($this->vsixFile('vscode-yaml', $version));
            $b->artifact('vscode', 'redhat.vscode-yaml', $version, "redhat.vscode-yaml-$version.vsix", [$this->vsix[$version], $readme, $icon], [
                'publisher' => 'redhat', 'name' => 'vscode-yaml', 'displayName' => 'YAML', 'description' => 'Prise en charge de YAML par Red Hat',
                'publisherDisplayName' => 'Red Hat', 'extensionId' => '2061917f-f76a-458a-8da9-f162de925899', 'publisherId' => 'eed56242-9699-4317-8bc7-e9f7cab5cc3b',
                'categories' => ['Programming Languages', 'Linters'], 'tags' => ['yaml', 'kubernetes'], 'targetPlatform' => 'universal', 'source' => 'openvsx',
                'properties' => ['Microsoft.VisualStudio.Code.Engine' => '^1.91.0', 'Microsoft.VisualStudio.Code.PreRelease' => 'false'],
                'assets' => ['Microsoft.VisualStudio.Services.Content.Details' => $readme, 'Microsoft.VisualStudio.Services.Icons.Default' => $icon],
            ]);
        }
        foreach (['linux-x64', 'win32-x64'] as $platform) {
            $this->vsix[$platform] = $b->blob($this->vsixFile('rust-analyzer', '0.3.2000 '.$platform));
            $b->artifact('vscode', 'rust-lang.rust-analyzer', '0.3.2000', "rust-lang.rust-analyzer-0.3.2000@$platform.vsix", [$this->vsix[$platform]], [
                'publisher' => 'rust-lang', 'name' => 'rust-analyzer', 'displayName' => 'rust-analyzer', 'targetPlatform' => $platform, 'source' => 'marketplace',
                'properties' => ['Microsoft.VisualStudio.Code.Engine' => '^1.78.0'], 'assets' => [],
            ]);
        }
        $name = $b->write($this->dir.'/inbox', $keys);
        $this->login();
        $this->trust($keys['publicKey']);
        self::assertSame(Job::SUCCEEDED, $this->import($name)->getStatus());
    }

    public function testUpdateCheckByNameReturnsAllVersionsNewestFirst(): void
    {
        // Ce qu'envoie VS Code pour vérifier les mises à jour des extensions installées
        $result = $this->query([[7, 'RedHat.vscode-yaml'], [7, 'inconnu.absente'], [8, 'Microsoft.VisualStudio.Code']], 0x1 | 0x2 | 0x10 | 0x80);
        self::assertSame(1, $result['resultMetadata'][0]['metadataItems'][0]['count']);
        $yaml = $result['extensions'][0];
        self::assertSame('2061917f-f76a-458a-8da9-f162de925899', $yaml['extensionId']);
        self::assertSame('redhat', $yaml['publisher']['publisherName']);
        self::assertSame('Red Hat', $yaml['publisher']['displayName']);
        self::assertSame(['1.15.0', '1.14.0'], array_column($yaml['versions'], 'version'));

        $latest = $yaml['versions'][0];
        self::assertSame('universal', $latest['targetPlatform']);
        self::assertContains(['key' => 'Microsoft.VisualStudio.Code.Engine', 'value' => '^1.91.0'], $latest['properties']);
        $files = array_column($latest['files'], 'source', 'assetType');
        self::assertSame('http://localhost/vscode/asset/redhat/vscode-yaml/1.15.0/Microsoft.VisualStudio.Services.VSIXPackage', $files['Microsoft.VisualStudio.Services.VSIXPackage']);
        self::assertArrayHasKey('Microsoft.VisualStudio.Services.Content.Details', $files);

        // Tous les fichiers annoncés sont servis
        $this->client->request('GET', $files['Microsoft.VisualStudio.Services.VSIXPackage']);
        self::assertResponseIsSuccessful();
        self::assertSame(hash('sha256', $this->body()), $this->vsix['1.15.0']);
        $this->client->request('GET', $files['Microsoft.VisualStudio.Services.Content.Details']);
        self::assertSame(self::README, $this->body());
        // Manifeste non collecté à part : lu dans le VSIX
        $this->client->request('GET', $files['Microsoft.VisualStudio.Code.Manifest']);
        self::assertSame('vscode-yaml', json_decode($this->body(), true, 512, \JSON_THROW_ON_ERROR)['name'] ?? null);
    }

    public function testPlatformSpecificBuilds(): void
    {
        $result = $this->query([[4, '']], 0x1 | 0x2 | 0x200);
        self::assertSame(0, $result['resultMetadata'][0]['metadataItems'][0]['count'], 'identifiant vide : rien');

        $result = $this->query([[10, 'rust']], 0x1 | 0x2 | 0x200);
        $rust = $result['extensions'][0];
        self::assertSame(['linux-x64', 'win32-x64'], array_column($rust['versions'], 'targetPlatform'));
        $windows = array_column($rust['versions'][1]['files'], 'source', 'assetType')['Microsoft.VisualStudio.Services.VSIXPackage'];
        self::assertStringEndsWith('?targetPlatform=win32-x64', $windows);
        $this->client->request('GET', $windows);
        self::assertSame($this->vsix['win32-x64'], hash('sha256', $this->body()));
        // Ancienne adresse de téléchargement
        $this->client->request('GET', '/vscode/gallery/publishers/rust-lang/vsextensions/rust-analyzer/0.3.2000/vspackage?targetPlatform=linux-x64');
        self::assertSame($this->vsix['linux-x64'], hash('sha256', $this->body()));
    }

    public function testSearchCategoriesPagingAndLatest(): void
    {
        $all = $this->query([[8, 'Microsoft.VisualStudio.Code']], 0x200);
        self::assertSame(2, $all['resultMetadata'][0]['metadataItems'][0]['count']);
        self::assertSame(['vscode-yaml'], array_column($this->query([[5, 'Linters']], 0x200)['extensions'], 'extensionName'));
        self::assertSame(['vscode-yaml'], array_column($this->query([[10, 'kubernetes']], 0x200)['extensions'], 'extensionName'));
        self::assertSame(['rust-analyzer'], array_column($this->query([[8, 'Microsoft.VisualStudio.Code']], 0x200, 1, 2)['extensions'], 'extensionName'));
        // Dernière version seulement
        self::assertSame(['1.15.0'], array_column($this->query([[7, 'redhat.vscode-yaml']], 0x1 | 0x200)['extensions'][0]['versions'], 'version'));

        $this->client->request('GET', '/vscode/gallery/redhat/vscode-yaml/latest');
        self::assertSame('1.15.0', $this->json()['versions'][0]['version']);
        $this->client->request('GET', '/vscode/gallery/redhat/absente/latest');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/vscode/item?itemName=redhat.vscode-yaml');
        self::assertResponseRedirects('/paquets/vscode/redhat.vscode-yaml');
    }

    public function testResourcesFromTheVsix(): void
    {
        $this->client->request('GET', '/vscode/unpkg/redhat/vscode-yaml/1.15.0/images/logo.svg');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        self::assertStringContainsString('sandbox', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        $this->client->request('GET', '/vscode/unpkg/redhat/vscode-yaml/1.15.0/../../extension.vsixmanifest');
        self::assertResponseStatusCodeSame(404);
    }

    public function testQuarantinedVersionIsHiddenAndRefused(): void
    {
        $quarantine = static::getContainer()->get(\App\Quarantine\Quarantine::class);
        $quarantine->apply('collecteur', 'Collecteur test', ['revision' => 1, 'updatedAt' => '2026-11-19T00:00:00+00:00', 'entries' => [
            ['ecosystem' => 'vscode', 'name' => 'redhat.vscode-yaml', 'version' => '1.15.0', 'reason' => 'CVE-2026-0001'],
        ]]);

        $yaml = $this->query([[7, 'redhat.vscode-yaml']], 0x1)['extensions'][0];
        self::assertSame(['1.14.0'], array_column($yaml['versions'], 'version'));
        $this->client->request('GET', '/vscode/asset/redhat/vscode-yaml/1.15.0/Microsoft.VisualStudio.Services.VSIXPackage');
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('CVE-2026-0001', $this->body());
    }

    /**
     * @param list<array{int, string}> $criteria
     *
     * @return array<string, mixed> premier résultat
     */
    private function query(array $criteria, int $flags, int $pageSize = 50, int $page = 1): array
    {
        $this->client->jsonRequest('POST', '/vscode/gallery/extensionquery', [
            'filters' => [['criteria' => array_map(static fn (array $c) => ['filterType' => $c[0], 'value' => $c[1]], $criteria), 'pageNumber' => $page, 'pageSize' => $pageSize, 'sortBy' => 0, 'sortOrder' => 0]],
            'flags' => $flags,
        ]);
        self::assertResponseIsSuccessful();

        return $this->json()['results'][0];
    }

    /** VSIX minimal : manifeste, package.json et une image */
    private function vsixFile(string $name, string $version): string
    {
        $path = $this->dir.'/'.bin2hex(random_bytes(4)).'.vsix';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('extension.vsixmanifest', '<PackageManifest/>');
        $zip->addFromString('extension/package.json', (string) json_encode(['name' => $name, 'version' => $version]));
        $zip->addFromString('extension/images/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $zip->close();
        $content = (string) file_get_contents($path);
        unlink($path);

        return $content;
    }
}
