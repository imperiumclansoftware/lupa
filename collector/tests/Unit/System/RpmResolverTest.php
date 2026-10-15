<?php

namespace App\Tests\Unit\System;

use App\Job\JobContext;
use App\Resolver\RpmResolver;
use App\System\RpmIndex;
use App\System\RpmRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/** Résolution des dépendances RPM sur une base primary.sqlite fictive (même schéma que createrepo). */
final class RpmResolverTest extends TestCase
{
    private string $db;
    private int $key = 0;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->db = tempnam(sys_get_temp_dir(), 'sas-rpm-');
        $this->pdo = new \PDO('sqlite:'.$this->db);
        $this->pdo->exec('CREATE TABLE packages (pkgKey INTEGER PRIMARY KEY, pkgId TEXT, name TEXT, arch TEXT, version TEXT, epoch TEXT, release TEXT, summary TEXT, description TEXT, url TEXT,
            time_file INTEGER, time_build INTEGER, rpm_license TEXT, rpm_vendor TEXT, rpm_group TEXT, rpm_buildhost TEXT, rpm_sourcerpm TEXT, rpm_header_start INTEGER, rpm_header_end INTEGER,
            rpm_packager TEXT, size_package INTEGER, size_installed INTEGER, size_archive INTEGER, location_href TEXT, location_base TEXT, checksum_type TEXT)');
        foreach (['provides', 'requires', 'recommends', 'conflicts', 'obsoletes'] as $table) {
            $this->pdo->exec("CREATE TABLE $table (name TEXT, flags TEXT, epoch TEXT, version TEXT, release TEXT, pkgKey INTEGER".('requires' === $table ? ', pre BOOLEAN DEFAULT FALSE' : '').')');
        }
        $this->pdo->exec('CREATE TABLE files (name TEXT, type TEXT, pkgKey INTEGER)');

        $this->package('httpd', '2.4.57', '5.el9', 'x86_64',
            requires: [['httpd-core', 'EQ', '0', '2.4.57', '5.el9'], ['/etc/mime.types'], ['system-logos-httpd'], ['(mod_http2 if httpd-core)'], ['(mod_lua if perl)'], ['(apr-util-openssl or apr-util-bdb)'], ['rpmlib(PayloadIsZstd)', 'LE', '0', '5.4.18', '1']],
            recommends: [['mod_lua']]);
        $this->package('httpd-core', '2.4.57', '5.el9', 'x86_64', requires: [['libc.so.6()(64bit)']]);
        $this->package('mod_http2', '2.0.26', '1.el9', 'x86_64');
        $this->package('mod_lua', '2.4.57', '5.el9', 'x86_64');
        $this->package('mailcap', '2.1.49', '5.el9', 'noarch', files: ['/etc/mime.types']);
        $this->package('almalinux-logos-httpd', '90.4', '1.el9', 'noarch', provides: [['system-logos-httpd']]);
        $this->package('apr-util-openssl', '1.6.1', '23.el9', 'x86_64');
        $this->package('apr-util-bdb', '1.6.1', '23.el9', 'x86_64');
        $this->package('glibc', '2.34', '100.el9', 'x86_64', provides: [['libc.so.6()(64bit)']]);
        $this->package('glibc', '2.34', '125.el9_5.1', 'x86_64', provides: [['libc.so.6()(64bit)']]);
        $this->package('glibc', '2.34', '125.el9_5.1', 'i686', provides: [['libc.so.6']]);
        $this->package('nodejs', '18.20.4', '1.module_el8.10.0+1+aaa', 'x86_64', epoch: '1');
        $this->package('nodejs', '20.18.0', '1.module_el8.10.0+2+bbb', 'x86_64', epoch: '1');
    }

    protected function tearDown(): void
    {
        unlink($this->db);
    }

    public function testCapabilitiesFilesAndRichDependencies(): void
    {
        $repository = $this->repository();
        [$selected, $log] = $this->select($repository, ['httpd'], false);
        self::assertEqualsCanonicalizing(
            ['httpd.x86_64', 'httpd-core.x86_64', 'mod_http2.x86_64', 'mailcap.noarch', 'almalinux-logos-httpd.noarch', 'apr-util-openssl.x86_64', 'glibc.x86_64'],
            array_keys($selected),
        );
        // Plus récente, architecture demandée (pas i686)
        self::assertSame('125.el9_5.1', $repository->package($selected['glibc.x86_64'])['r']);
        self::assertSame('', $log);

        [$selected] = $this->select($repository, ['httpd'], true);
        self::assertArrayHasKey('mod_lua.x86_64', $selected);
    }

    public function testPrimaryXmlIsRegenerated(): void
    {
        $repository = $this->repository();
        $id = $repository->versions('httpd')[0];
        $xml = RpmIndex::xml($repository->pdo(0), $repository->package($id)['key']);
        self::assertStringContainsString('<name>httpd</name><arch>x86_64</arch><version epoch="0" ver="2.4.57" rel="5.el9"/>', $xml);
        self::assertStringContainsString('<location href="Packages/h/httpd-2.4.57-5.el9.x86_64.rpm"/>', $xml);
        self::assertStringContainsString('<rpm:entry name="httpd-core" flags="EQ" epoch="0" ver="2.4.57" rel="5.el9"/>', $xml);
        self::assertStringContainsString('<rpm:entry name="(mod_http2 if httpd-core)"/>', $xml);
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML('<metadata xmlns="http://linux.duke.edu/metadata/common" xmlns:rpm="http://linux.duke.edu/metadata/rpm">'.$xml.'</metadata>'));
    }

    public function testEl8ModuleStreams(): void
    {
        $repository = $this->repository();
        RpmIndex::modules(<<<'YAML'
            ---
            document: modulemd
            version: 2
            data:
              name: nodejs
              stream: "18"
              artifacts:
                rpms:
                - nodejs-1:18.20.4-1.module_el8.10.0+1+aaa.x86_64
            ...
            ---
            document: modulemd
            version: 2
            data:
              name: nodejs
              stream: "20"
              artifacts:
                rpms:
                - nodejs-1:20.18.0-1.module_el8.10.0+2+bbb.x86_64
            ...
            ---
            document: modulemd-defaults
            version: 1
            data:
              module: nodejs
              stream: "18"
              profiles:
                18: [common]
            ...
            YAML, $repository);
        self::assertSame(['nodejs' => '18'], $repository->defaultStreams);

        [$selected] = $this->select($repository, ['nodejs'], false);
        self::assertSame('18.20.4', $repository->package($selected['nodejs.x86_64'])['v']);
        [$selected] = $this->select($repository, ['@nodejs:20', 'nodejs'], false);
        self::assertSame('20.18.0', $repository->package($selected['nodejs.x86_64'])['v']);
    }

    private function repository(): RpmRepository
    {
        return new RpmRepository([['name' => 'AppStream', 'base' => 'https://rpm.example', 'sqlite' => $this->db]], ['x86_64', 'noarch']);
    }

    /** @return array{array<string, int>, string} */
    private function select(RpmRepository $repository, array $specs, bool $recommends): array
    {
        $db = $this->createStub(Connection::class);
        $log = '';
        $db->method('executeStatement')->willReturnCallback(static function (string $sql, array $params) use (&$log): int {
            $log .= (string) ($params[0] ?? '');

            return 1;
        });
        $context = new JobContext($db, 1);
        $resolver = (new \ReflectionClass(RpmResolver::class))->newInstanceWithoutConstructor();
        $selected = $resolver->select($repository, $specs, $recommends, $context);
        $context->flushLog();

        return [$selected, $log];
    }

    /**
     * @param list<list<string>> $requires [nom, flags, époque, version, release]
     * @param list<list<string>> $provides
     * @param list<list<string>> $recommends
     * @param list<string>       $files
     */
    private function package(string $name, string $version, string $release, string $arch, array $requires = [], array $provides = [], array $recommends = [], array $files = [], string $epoch = '0'): void
    {
        $key = ++$this->key;
        $this->pdo->prepare('INSERT INTO packages (pkgKey, pkgId, name, arch, version, epoch, release, summary, description, url, time_file, time_build, rpm_license, rpm_vendor, rpm_group, rpm_buildhost, rpm_sourcerpm, rpm_header_start, rpm_header_end, rpm_packager, size_package, size_installed, size_archive, location_href, checksum_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, 0, 0, ?, 100, 200, 300, ?, ?)')
            ->execute([$key, hash('sha256', $name.$version.$release.$arch), $name, $arch, $version, $epoch, $release, $name, $name.' description', 'https://example.org', 'MIT', 'AlmaLinux', 'Unspecified', 'build', $name.'.src.rpm', 'AlmaLinux',
                sprintf('Packages/%s/%s-%s-%s.%s.rpm', $name[0], $name, $version, $release, $arch), 'sha256']);
        $insert = fn (string $table, array $dep) => $this->pdo->prepare("INSERT INTO $table (name, flags, epoch, version, release, pkgKey) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$dep[0], $dep[1] ?? null, $dep[2] ?? null, $dep[3] ?? null, $dep[4] ?? null, $key]);
        foreach ([[$name, 'EQ', $epoch, $version, $release], ...$provides] as $dep) {
            $insert('provides', $dep);
        }
        foreach ($requires as $dep) {
            $insert('requires', $dep);
        }
        foreach ($recommends as $dep) {
            $insert('recommends', $dep);
        }
        foreach ($files as $file) {
            $this->pdo->prepare('INSERT INTO files (name, type, pkgKey) VALUES (?, ?, ?)')->execute([$file, 'file', $key]);
        }
    }
}
