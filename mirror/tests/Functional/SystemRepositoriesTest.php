<?php

namespace App\Tests\Functional;

use App\Entity\Job;
use App\Tests\MirrorWebTestCase;
use App\Tests\Support\BundleBuilder;
use Sas\Bundle\Signer;

/** Dépôts apt et dnf régénérés par Romulus à partir d'un bundle : index, signature, paquets, quarantaine. */
final class SystemRepositoriesTest extends MirrorWebTestCase
{
    private const string CONTROL = "Package: hello\nVersion: 2.10-3\nArchitecture: amd64\nDepends: libc6 (>= 2.34)\nFilename: pool/main/h/hello/hello_2.10-3_amd64.deb\nSize: %d\nSHA256: %s\nDescription: example package\n";

    /** @var array<string, string> */
    private array $sha = [];
    /** @var array<string, string> */
    private array $data = [];
    /** @var array{secretKey: string, publicKey: string} */
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = Signer::generateKeyPair();
        $builder = new BundleBuilder($this->dir.'/source');
        foreach (['deb' => 'contenu du .deb', 'debAll' => 'paquet arch all', 'rpm' => 'contenu du .rpm', 'noarch' => 'rpm noarch'] as $role => $content) {
            $this->data[$role] = $content;
            $this->sha[$role] = $builder->blob($content);
        }
        $builder->artifact('deb', 'hello', '2.10-3', 'debian-12/pool/main/h/hello/hello_2.10-3_amd64.deb', [$this->sha['deb']], [
            'distribution' => 'debian-12', 'architecture' => 'amd64', 'component' => 'main', 'suite' => 'bookworm',
            'control' => sprintf(self::CONTROL, \strlen($this->data['deb']), $this->sha['deb']),
        ]);
        $builder->artifact('deb', 'hello-doc', '2.10-3', 'debian-12/pool/main/h/hello/hello-doc_2.10-3_all.deb', [$this->sha['debAll']], [
            'distribution' => 'debian-12', 'architecture' => 'all', 'component' => 'main', 'suite' => 'bookworm',
            'control' => "Package: hello-doc\nVersion: 2.10-3\nArchitecture: all\nFilename: pool/main/h/hello/hello-doc_2.10-3_all.deb\nSize: 15\nSHA256: {$this->sha['debAll']}\n",
        ]);
        foreach (['rpm' => ['hello', 'x86_64'], 'noarch' => ['hello-data', 'noarch']] as $role => [$name, $arch]) {
            $file = sprintf('%s-2.10-8.el9.%s.rpm', $name, $arch);
            $builder->artifact('rpm', $name, '2.10-8.el9', 'almalinux-9/x86_64/'.$file, [$this->sha[$role]], [
                'distribution' => 'almalinux-9', 'architecture' => $arch, 'repoArch' => 'x86_64', 'repository' => 'AppStream',
                'primary' => sprintf('<package type="rpm"><name>%s</name><arch>%s</arch><version epoch="0" ver="2.10" rel="8.el9"/><checksum type="sha256" pkgid="YES">%s</checksum><summary>x</summary><location href="Packages/h/%s"/><format><rpm:provides><rpm:entry name="%1$s"/></rpm:provides><file>/usr/bin/%1$s</file></format></package>', $name, $arch, $this->sha[$role], $file),
            ]);
        }
        $name = $builder->write($this->dir.'/inbox', $this->keys);
        $this->login();
        $this->trust($this->keys['publicKey']);
        self::assertSame(Job::SUCCEEDED, $this->import($name)->getStatus());
    }

    public function testAptRepository(): void
    {
        $this->client->request('GET', '/deb/debian-12/dists/bookworm/Release');
        self::assertResponseIsSuccessful();
        $release = $this->body();
        self::assertStringContainsString("Codename: bookworm\n", $release);
        self::assertStringContainsString("Architectures: amd64\n", $release);
        self::assertStringContainsString("Components: main\n", $release);

        $this->client->request('GET', '/deb/debian-12/dists/bookworm/main/binary-amd64/Packages');
        $packages = $this->body();
        // Paquet « all » publié dans l'architecture
        self::assertStringContainsString('Package: hello-doc', $packages);
        self::assertStringContainsString(sprintf(" %s %16d main/binary-amd64/Packages\n", hash('sha256', $packages), \strlen($packages)), $release);
        $this->client->request('GET', '/deb/debian-12/dists/bookworm/main/binary-amd64/Packages.gz');
        self::assertSame($packages, gzdecode($this->body()));

        $this->client->request('GET', '/deb/debian-12/pool/main/h/hello/hello_2.10-3_amd64.deb');
        self::assertResponseIsSuccessful();
        self::assertSame($this->data['deb'], $this->body());
        $this->client->request('GET', '/deb/debian-12/pool/main/h/hello/absent_1.0_amd64.deb');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/deb/debian-13/dists/trixie/Release');
        self::assertResponseStatusCodeSame(404);

        // Signature : vérifiée par gpgv avec la clé publiée par le miroir
        $this->client->request('GET', '/deb/debian-12/dists/bookworm/InRelease');
        $inRelease = $this->body();
        self::assertStringStartsWith("-----BEGIN PGP SIGNED MESSAGE-----\nHash: SHA256\n\n", $inRelease);
        $this->client->request('GET', '/deb/romulus.gpg');
        $key = $this->body();
        self::assertSame(0xC6, \ord($key[0])); // paquet « clé publique » (format OpenPGP récent)
        if (null !== $gpgv = (new \Symfony\Component\Process\ExecutableFinder())->find('gpgv')) {
            file_put_contents($this->dir.'/romulus.gpg', $key);
            file_put_contents($this->dir.'/InRelease', $inRelease);
            $process = new \Symfony\Component\Process\Process([$gpgv, '--keyring', $this->dir.'/romulus.gpg', '--output', $this->dir.'/out', $this->dir.'/InRelease']);
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame(rtrim($release), rtrim((string) file_get_contents($this->dir.'/out')));
        }

        $this->client->request('GET', '/deb/debian-12/romulus.sources');
        self::assertStringContainsString("URIs: http://localhost/deb/debian-12\nSuites: bookworm\nComponents: main\n", $this->body());
    }

    public function testDnfRepository(): void
    {
        $this->client->request('GET', '/rpm/almalinux-9/x86_64/repodata/repomd.xml');
        self::assertResponseIsSuccessful();
        $repomd = new \DOMDocument();
        self::assertTrue($repomd->loadXML($this->body()));
        $locations = [];
        foreach ($repomd->getElementsByTagName('data') as $data) {
            $locations[$data->getAttribute('type')] = [$data->getElementsByTagName('location')->item(0)->getAttribute('href'), trim($data->getElementsByTagName('checksum')->item(0)->textContent)];
        }
        self::assertSame(['primary', 'filelists', 'other'], array_keys($locations));

        [$href, $sha] = $locations['primary'];
        $this->client->request('GET', '/rpm/almalinux-9/x86_64/'.$href);
        $gz = $this->body();
        self::assertSame($sha, hash('sha256', $gz));
        $primary = (string) gzdecode($gz);
        self::assertStringContainsString('packages="2"', $primary);
        self::assertStringContainsString('<location href="Packages/hello-2.10-8.el9.x86_64.rpm"/>', $primary);
        self::assertTrue((new \DOMDocument())->loadXML($primary));

        [$href] = $locations['filelists'];
        $this->client->request('GET', '/rpm/almalinux-9/x86_64/'.$href);
        self::assertStringContainsString('<file>/usr/bin/hello</file>', (string) gzdecode($this->body()));

        $this->client->request('GET', '/rpm/almalinux-9/x86_64/Packages/hello-data-2.10-8.el9.noarch.rpm');
        self::assertSame($this->data['noarch'], $this->body());
        $this->client->request('GET', '/rpm/almalinux-9/aarch64/repodata/repomd.xml');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/rpm/almalinux-9/romulus.repo');
        self::assertStringContainsString("baseurl=http://localhost/rpm/almalinux-9/\$basearch/\n", $this->body());
        self::assertStringContainsString("gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-AlmaLinux-9\n", $this->body());

        $this->client->request('GET', '/api/distributions');
        $items = array_column($this->json()['items'], null, 'id');
        self::assertSame(['amd64'], $items['debian-12']['architectures']);
        self::assertSame(['x86_64'], $items['almalinux-9']['architectures']);
    }

    public function testQuarantineHidesSystemPackages(): void
    {
        $builder = (new BundleBuilder($this->dir.'/source-2'))->blacklist(1, [
            ['ecosystem' => 'deb', 'name' => 'hello', 'version' => '2.10-3', 'reason' => 'test'],
            ['ecosystem' => 'rpm', 'name' => 'hello', 'version' => '0:2.10-8.el9', 'reason' => 'test'],
        ]);
        self::assertSame(Job::SUCCEEDED, $this->import($builder->write($this->dir.'/inbox', $this->keys, 2, false))->getStatus());

        $this->client->request('GET', '/deb/debian-12/dists/bookworm/main/binary-amd64/Packages');
        self::assertStringNotContainsString("Package: hello\n", $this->body());
        self::assertStringContainsString('Package: hello-doc', $this->body());
        $this->client->request('GET', '/deb/debian-12/pool/main/h/hello/hello_2.10-3_amd64.deb');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/rpm/almalinux-9/x86_64/repodata/repomd.xml');
        preg_match('#repodata/[a-f0-9]+-primary\.xml\.gz#', $this->body(), $m);
        $this->client->request('GET', '/rpm/almalinux-9/x86_64/'.$m[0]);
        self::assertStringContainsString('packages="1"', (string) gzdecode($this->body()));
        $this->client->request('GET', '/rpm/almalinux-9/x86_64/Packages/hello-2.10-8.el9.x86_64.rpm');
        self::assertResponseStatusCodeSame(403);
    }
}
