<?php

namespace App\Tests\Unit\System;

use App\System\Signatures;
use PHPUnit\Framework\TestCase;
use Sas\Bundle\Distributions;
use Sas\Bundle\Ecosystem;

/** Catalogue des distributions : clés livrées avec Rémus, identifiants servis par Romulus. */
final class DistributionsTest extends TestCase
{
    public function testEveryDistributionIsUsable(): void
    {
        $keyDir = \dirname(__DIR__, 3).'/resources/keys';
        foreach (Distributions::all() as $id => $dist) {
            // Identifiant repris dans les adresses de Romulus (/deb/{distribution}/…, /rpm/{distribution}/…)
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $id);
            self::assertNotEmpty($dist['architectures'], $id);
            self::assertNotEmpty(array_filter($dist['sections'], static fn (array $s) => $s['default']), $id.' : aucune section par défaut');

            $keys = Ecosystem::Deb === $dist['ecosystem']
                ? array_merge(...array_column($dist['suites'] ?? [], 'keys'))
                : array_merge(...array_column($dist['repos'] ?? [], 'keys'));
            self::assertNotEmpty($keys, $id);
            foreach ($keys as $key) {
                $file = $keyDir.'/'.$key.'.asc';
                self::assertFileExists($file, $id);
                self::assertNotSame('', Signatures::dearmor((string) file_get_contents($file)), $key);
            }
        }
    }

    public function testUbuntuServesArm64FromPorts(): void
    {
        foreach (['ubuntu-2204', 'ubuntu-2404'] as $id) {
            $dist = Distributions::find($id) ?? self::fail($id);
            foreach ($dist['suites'] as $suite) {
                self::assertSame('https://ports.ubuntu.com/ubuntu-ports', $suite['ports'] ?? null);
            }
            self::assertArrayHasKey('universe', $dist['sections']);
        }
        self::assertArrayNotHasKey('non-free-firmware', Distributions::find('debian-11')['sections'] ?? []);
        self::assertTrue(Distributions::find('debian-11')['eol'] ?? false);
    }
}
