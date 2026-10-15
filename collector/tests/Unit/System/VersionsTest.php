<?php

namespace App\Tests\Unit\System;

use PHPUnit\Framework\TestCase;
use Sas\Bundle\DebVersion;
use Sas\Bundle\PackageKey;
use Sas\Bundle\Ecosystem;
use Sas\Bundle\RpmVersion;

/** Comparaisons de versions conformes à dpkg et rpmvercmp. */
final class VersionsTest extends TestCase
{
    /** @return iterable<array{string, string, int}> */
    public static function debian(): iterable
    {
        yield ['1.0', '1.0', 0];
        yield ['1.0-1', '1.0-2', -1];
        yield ['1.0~rc1', '1.0', -1];         // « ~ » avant tout, même la fin
        yield ['1.0', '1.0+deb12u1', -1];
        yield ['1:0.9', '2.0', 1];            // époque
        yield ['1.22.1-9+deb12u10', '1.22.1-9+deb12u2', 1];
        yield ['2.36-9+deb12u14', '2.36-9+deb12u9', 1];
        yield ['1.0a', '1.0', 1];
        yield ['1.0-1', '1.0', 1];
        yield ['10.0', '9.9', 1];
        yield ['0.0~git20230101', '0.0', -1];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('debian')]
    public function testDebianOrdering(string $a, string $b, int $expected): void
    {
        self::assertSame($expected, DebVersion::compare($a, $b) <=> 0, "$a ? $b");
        self::assertSame(-$expected, DebVersion::compare($b, $a) <=> 0, "$b ? $a");
    }

    /** @return iterable<array{string, string, int}> */
    public static function rpm(): iterable
    {
        yield ['1.0-1.el9', '1.0-1.el9', 0];
        yield ['1.20.1-14.el9', '1.20.1-14.el9_2.1', -1];
        yield ['1:1.14.1-9', '1.26.3-6', 1];
        yield ['2.4.6-99.el7', '2.4.6-97.el7', 1];
        yield ['1.0~rc1-1', '1.0-1', -1];
        yield ['1.0^git1-1', '1.0-1', 1];
        yield ['1.0^git1-1', '1.0.1-1', -1];
        yield ['5.32.1-480', '5.26.3-422', 1];
        yield ['1.0a-1', '1.0-1', 1];
        yield ['1.0.10-1', '1.0.9-1', 1];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rpm')]
    public function testRpmOrdering(string $a, string $b, int $expected): void
    {
        self::assertSame($expected, RpmVersion::compare($a, $b) <=> 0, "$a ? $b");
        self::assertSame(-$expected, RpmVersion::compare($b, $a) <=> 0, "$b ? $a");
    }

    public function testConstraints(): void
    {
        self::assertTrue(DebVersion::satisfies('2.36-9', '>=', '2.34'));
        self::assertFalse(DebVersion::satisfies('2.36-9', '<<', '2.36'));
        self::assertTrue(DebVersion::satisfies('1.22.1-9', '=', '1.22.1-9'));
        // Release absente de la contrainte : seule la version compte (comme rpm)
        self::assertTrue(RpmVersion::satisfies('2.34-100.el9', 'GE', '2.34'));
        self::assertTrue(RpmVersion::satisfies('1:1.20.1-14.el9', 'EQ', '1:1.20.1'));
        self::assertFalse(RpmVersion::satisfies('1.20.1-14.el9', 'EQ', '1:1.20.1'));
        self::assertSame('1:2.3-4.el9', RpmVersion::format(1, '2.3', '4.el9'));
        self::assertSame('2.3', RpmVersion::format(0, '2.3', null));
    }

    public function testPackageKeys(): void
    {
        self::assertSame(PackageKey::of(Ecosystem::Rpm, 'openssl', '0:3.0.7-27.el9'), PackageKey::of(Ecosystem::Rpm, 'openssl', '3.0.7-27.el9'));
        self::assertNotSame(PackageKey::of(Ecosystem::Rpm, 'openssl', '1:3.0.7-27.el9'), PackageKey::of(Ecosystem::Rpm, 'openssl', '3.0.7-27.el9'));
        self::assertSame(PackageKey::of(Ecosystem::Deb, 'Curl', '7.88.1-10'), PackageKey::of(Ecosystem::Deb, 'curl', '7.88.1-10'));
    }
}
