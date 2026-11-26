<?php

namespace App\Tests\Unit\System;

use App\System\PrimaryXml;
use App\System\RpmIndex;
use PHPUnit\Framework\TestCase;

/** primary.xml (extrait du dépôt nginx pour EL9) converti au schéma de primary_db. */
final class PrimaryXmlTest extends TestCase
{
    public function testConversion(): void
    {
        $db = sys_get_temp_dir().'/sas-primary-'.bin2hex(random_bytes(4)).'.db';
        try {
            PrimaryXml::convert(__DIR__.'/../../fixtures/rpm/primary.xml', $db);
            $pdo = new \PDO('sqlite:'.$db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $q = static fn (string $sql): \PDOStatement => $pdo->query($sql) ?: throw new \LogicException($sql);

            self::assertSame(['nginx', 'nginx-module-njs'], $q('SELECT name FROM packages ORDER BY name')->fetchAll(\PDO::FETCH_COLUMN));
            $nginx = (int) $q("SELECT pkgKey FROM packages WHERE name = 'nginx'")->fetchColumn();
            // Capacité fournie, dépendance de pré-installation, fichier : utilisés par la résolution
            self::assertSame('1', (string) $q("SELECT COUNT(*) FROM provides WHERE name = 'webserver' AND pkgKey = $nginx")->fetchColumn());
            self::assertSame('TRUE', $q("SELECT pre FROM requires WHERE name = 'shadow-utils' AND pkgKey = $nginx")->fetchColumn());
            self::assertSame('1', (string) $q("SELECT COUNT(*) FROM files WHERE name = '/usr/sbin/nginx'")->fetchColumn());

            $xml = RpmIndex::xml($pdo, $nginx);
            self::assertStringContainsString('<version epoch="1" ver="1.20.2" rel="1.el9.ngx"/>', $xml);
            self::assertStringContainsString('<rpm:entry name="shadow-utils" pre="1"/>', $xml);
            self::assertStringContainsString('<file type="dir">/etc/nginx</file>', $xml);
        } finally {
            @unlink($db);
        }
    }
}
