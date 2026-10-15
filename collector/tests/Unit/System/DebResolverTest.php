<?php

namespace App\Tests\Unit\System;

use App\Job\JobContext;
use App\Resolver\DebResolver;
use App\System\DebIndex;
use App\System\DebRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/** Résolution des dépendances Debian sur un index Packages fictif. */
final class DebResolverTest extends TestCase
{
    private const string PACKAGES = <<<'TXT'
        Package: nginx
        Version: 1.22.1-9
        Architecture: amd64
        Depends: nginx-common (= 1.22.1-9), libc6 (>= 2.34), libssl3 | libssl1.1, mail-transport-agent
        Recommends: nginx-doc
        Filename: pool/main/n/nginx/nginx_1.22.1-9_amd64.deb
        Size: 100
        SHA256: aa
        Description: small, powerful, scalable web/proxy server

        Package: nginx-common
        Version: 1.22.1-9
        Architecture: all
        Depends: lsb-base | sysvinit-utils, ghost-package
        Filename: pool/main/n/nginx/nginx-common_1.22.1-9_all.deb
        Size: 10
        SHA256: bb

        Package: nginx-doc
        Version: 1.22.1-9
        Architecture: all
        Filename: pool/main/n/nginx/nginx-doc_1.22.1-9_all.deb
        Size: 10
        SHA256: cc

        Package: libc6
        Version: 2.31-13
        Architecture: amd64
        Filename: pool/main/g/glibc/libc6_2.31-13_amd64.deb
        Size: 10
        SHA256: d1

        Package: libc6
        Version: 2.36-9+deb12u4
        Architecture: amd64
        Filename: pool/main/g/glibc/libc6_2.36-9+deb12u4_amd64.deb
        Size: 10
        SHA256: d2

        Package: libc6
        Version: 2.36-9+deb12u4
        Architecture: arm64
        Filename: pool/main/g/glibc/libc6_2.36-9+deb12u4_arm64.deb
        Size: 10
        SHA256: d3

        Package: libssl3
        Version: 3.0.15-1~deb12u1
        Architecture: amd64
        Depends: libc6 (>= 2.34)
        Filename: pool/main/o/openssl/libssl3_3.0.15-1~deb12u1_amd64.deb
        Size: 10
        SHA256: ee

        Package: sysvinit-utils
        Version: 3.06-4
        Architecture: amd64
        Filename: pool/main/s/sysvinit/sysvinit-utils_3.06-4_amd64.deb
        Size: 10
        SHA256: ff

        Package: postfix
        Version: 3.7.11-0+deb12u1
        Architecture: amd64
        Provides: mail-transport-agent
        Filename: pool/main/p/postfix/postfix_3.7.11-0+deb12u1_amd64.deb
        Size: 10
        SHA256: a1

        Package: exim4
        Version: 4.96-15+deb12u6
        Architecture: all
        Provides: mail-transport-agent
        Filename: pool/main/e/exim4/exim4_4.96-15+deb12u6_all.deb
        Size: 10
        SHA256: a2

        TXT;

    private DebRepository $repository;
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'sas-deb-');
        file_put_contents($this->file, self::PACKAGES);
        $index = (new \ReflectionClass(DebIndex::class))->newInstanceWithoutConstructor();
        $this->repository = new DebRepository([['base' => 'https://deb.example', 'file' => $this->file, 'suite' => 'bookworm']]);
        $this->repository->add(0, $index->parse($this->file, 'main'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->file.'*') ?: [] as $f) {
            unlink($f);
        }
    }

    public function testDependenciesAlternativesAndVirtualPackages(): void
    {
        [$selected, $log] = $this->select(['nginx'], false);
        self::assertEqualsCanonicalizing(['nginx', 'nginx-common', 'libc6', 'libssl3', 'sysvinit-utils', 'exim4'], array_keys($selected));
        // Version la plus récente pour l'architecture demandée
        self::assertSame('2.36-9+deb12u4', $this->repository->packages[$selected['libc6']]['v']);
        self::assertSame('amd64', $this->repository->packages[$selected['libc6']]['a']);
        // Dépendance absente : signalée, sans faire échouer la liste
        self::assertStringContainsString('dépendance introuvable : ghost-package', $log);
        // Texte complet du paquet, pour Romulus
        self::assertStringStartsWith("Package: nginx\nVersion: 1.22.1-9\n", $this->repository->stanza($selected['nginx']));
        self::assertStringEndsWith("Description: small, powerful, scalable web/proxy server\n", $this->repository->stanza($selected['nginx']));
    }

    public function testRecommendsAndExactVersion(): void
    {
        [$selected] = $this->select(['nginx'], true);
        self::assertArrayHasKey('nginx-doc', $selected);

        [$selected] = $this->select(['libc6=2.31-13'], false);
        self::assertSame('2.31-13', $this->repository->packages[$selected['libc6']]['v']);

        [$selected] = $this->select(['nginx*'], false);
        self::assertArrayHasKey('nginx-doc', $selected);
    }

    public function testUnknownPackageFails(): void
    {
        $this->expectExceptionMessage('Paquet introuvable : apache2');
        $this->select(['apache2'], false);
    }

    /** @return array{array<string, int>, string} */
    private function select(array $specs, bool $recommends): array
    {
        $db = $this->createStub(Connection::class);
        $log = '';
        $db->method('executeStatement')->willReturnCallback(static function (string $sql, array $params) use (&$log): int {
            $log .= (string) ($params[0] ?? '');

            return 1;
        });
        $context = new JobContext($db, 1);
        $resolver = (new \ReflectionClass(DebResolver::class))->newInstanceWithoutConstructor();
        $selected = $resolver->select($this->repository, $specs, 'amd64', $recommends, $context);
        $context->flushLog();

        return [$selected, $log];
    }
}
