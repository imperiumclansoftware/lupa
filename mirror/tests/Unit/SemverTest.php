<?php

namespace App\Tests\Unit;

use App\Registry\Semver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SemverTest extends TestCase
{
    /** @return iterable<string, array{string, string}> paires (plus petite, plus grande) */
    public static function ordered(): iterable
    {
        yield 'alpha < beta' => ['1.0.0-alpha', '1.0.0-beta'];
        yield 'préversion < finale' => ['1.0.0-beta', '1.0.0'];
        yield 'correctif' => ['1.0.0', '1.0.1'];
        yield 'numérique et non lexical' => ['1.0.1', '1.10.0'];
        yield 'mineure' => ['1.9.9', '1.10.0'];
        yield 'majeure' => ['9.0.0', '10.0.0'];
        yield 'identifiants numériques' => ['1.0.0-beta.2', '1.0.0-beta.11'];
        yield 'numérique < alphanumérique' => ['1.0.0-alpha.1', '1.0.0-alpha.beta'];
        yield 'moins de champs < plus de champs' => ['1.0.0-alpha', '1.0.0-alpha.1'];
        yield 'rc.1 < finale' => ['2.0.0-rc.1', '2.0.0'];
        yield 'préfixe v' => ['v1.2.3', '1.2.4'];
    }

    #[DataProvider('ordered')]
    public function testCompare(string $lower, string $higher): void
    {
        self::assertLessThan(0, Semver::compare($lower, $higher));
        self::assertGreaterThan(0, Semver::compare($higher, $lower));
    }

    public function testEqualVersions(): void
    {
        self::assertSame(0, Semver::compare('1.2.3', '1.2.3'));
        self::assertSame(0, Semver::compare('1.2.3-beta.1', '1.2.3-beta.1'));
        // Les métadonnées de construction sont ignorées
        self::assertSame(0, Semver::compare('1.2.3+build.5', '1.2.3'));
    }

    public function testSortsAFullList(): void
    {
        $versions = ['1.10.0', '1.0.0', '1.0.0-beta', '1.0.1', '1.0.0-alpha', '1.0.0-beta.11', '1.0.0-beta.2'];
        usort($versions, Semver::compare(...));

        self::assertSame(['1.0.0-alpha', '1.0.0-beta', '1.0.0-beta.2', '1.0.0-beta.11', '1.0.0', '1.0.1', '1.10.0'], $versions);
    }

    public function testNonSemverFallsBackToNaturalOrder(): void
    {
        self::assertLessThan(0, Semver::compare('1.2', '1.10'));
    }

    public function testIsPrerelease(): void
    {
        self::assertTrue(Semver::isPrerelease('1.0.0-alpha'));
        self::assertTrue(Semver::isPrerelease('2.0.0-rc.1+build'));
        self::assertFalse(Semver::isPrerelease('1.0.0'));
        self::assertFalse(Semver::isPrerelease('1.0.0+build.1'));
        self::assertFalse(Semver::isPrerelease('pas-une-version'));
    }
}
