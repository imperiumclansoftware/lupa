<?php

namespace App\Tests\Functional;

use App\Entity\Job;
use App\Tests\MirrorWebTestCase;
use App\Tests\Support\BundleBuilder;
use Sas\Bundle\Signer;

/**
 * Distributions personnalisées de Rémus : inconnues de Romulus, décrites par la métadonnée « distributionInfo »
 * de leurs paquets, puis servies comme les distributions intégrées.
 */
final class CustomDistributionsTest extends MirrorWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $keys = Signer::generateKeyPair();
        $builder = new BundleBuilder($this->dir.'/source');
        $deb = $builder->blob('paquet interne');
        $rpm = $builder->blob('rpm interne');
        $builder->artifact('deb', 'outil', '1.0-1', 'debian-12-interne/pool/main/o/outil/outil_1.0-1_amd64.deb', [$deb], [
            'distribution' => 'debian-12-interne', 'architecture' => 'amd64', 'component' => 'main', 'suite' => 'bookworm',
            'control' => sprintf("Package: outil\nVersion: 1.0-1\nArchitecture: amd64\nFilename: pool/main/o/outil/outil_1.0-1_amd64.deb\nSize: %d\nSHA256: %s\n", \strlen('paquet interne'), $deb),
            'distributionInfo' => ['ecosystem' => 'deb', 'label' => 'Debian 12 interne', 'codename' => 'bookworm-interne', 'release' => '12', 'eol' => false, 'architectures' => ['amd64'], 'sections' => ['main']],
        ]);
        $builder->artifact('rpm', 'outil', '1.0-1.el9', 'rocky-9-interne/x86_64/outil-1.0-1.el9.x86_64.rpm', [$rpm], [
            'distribution' => 'rocky-9-interne', 'architecture' => 'x86_64', 'repoArch' => 'x86_64', 'repository' => 'Interne',
            'primary' => sprintf('<package type="rpm"><name>outil</name><arch>x86_64</arch><version epoch="0" ver="1.0" rel="1.el9"/><checksum type="sha256" pkgid="YES">%s</checksum><summary>x</summary><location href="Packages/o/outil-1.0-1.el9.x86_64.rpm"/><format></format></package>', $rpm),
            'signingKey' => (string) file_get_contents(__DIR__.'/../fixtures/third-party-key.asc'),
            'distributionInfo' => ['ecosystem' => 'rpm', 'label' => 'Rocky 9 interne', 'codename' => '9', 'release' => '9', 'eol' => false, 'architectures' => ['x86_64'], 'sections' => ['Interne']],
        ]);
        $name = $builder->write($this->dir.'/inbox', $keys);
        $this->login();
        $this->trust($keys['publicKey']);
        self::assertSame(Job::SUCCEEDED, $this->import($name)->getStatus());
    }

    public function testCustomDistributionsAreServed(): void
    {
        $this->client->request('GET', '/deb/debian-12-interne/dists/bookworm-interne/Release');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString("Codename: bookworm-interne\n", $this->body());
        $this->client->request('GET', '/deb/debian-12-interne/romulus.sources');
        self::assertStringContainsString("Suites: bookworm-interne\n", $this->body());

        // Pas de clé préinstallée sur les postes : seulement celle publiée par Romulus
        $this->client->request('GET', '/rpm/rocky-9-interne/romulus.repo');
        self::assertStringContainsString("gpgkey=http://localhost/rpm/rocky-9-interne/keys/F9BD3017B38B644C8F9EFF2D29A000A37390BBF1.asc\n", $this->body());
        self::assertStringNotContainsString('file://', $this->body());
        $this->client->request('GET', '/rpm/rocky-9-interne/x86_64/repodata/repomd.xml');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/distributions');
        $items = array_column($this->json()['items'], null, 'id');
        self::assertSame(['Debian 12 interne', 'bookworm-interne'], [$items['debian-12-interne']['label'] ?? null, $items['debian-12-interne']['codename'] ?? null]);
        self::assertSame('Rocky 9 interne', $items['rocky-9-interne']['label'] ?? null);
    }
}
