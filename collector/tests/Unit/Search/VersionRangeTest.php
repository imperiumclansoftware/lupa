<?php

namespace App\Tests\Unit\Search;

use App\Search\PatternExpander;
use App\Search\VersionFilter;
use App\Search\VersionRange;
use PHPUnit\Framework\TestCase;
use Sas\Bundle\Ecosystem;

/** Plages « [toutes] » : lecture de la ligne et choix des versions avec les règles de chaque écosystème. */
final class VersionRangeTest extends TestCase
{
    public function testMarkerDetection(): void
    {
        self::assertTrue(VersionRange::isRange('react@>=16 <19 [toutes]'));
        self::assertTrue(VersionRange::isRange('django>=4,<6 [TOUTES] '));
        self::assertFalse(VersionRange::isRange('react@^18'));
        self::assertFalse(VersionRange::isRange('django[argon2]>=4'));
        self::assertTrue(PatternExpander::needsExpansion('react@16 - 18 [toutes]'));
        self::assertTrue(PatternExpander::needsExpansion('@angular/*@^18'));
        self::assertFalse(PatternExpander::needsExpansion('lodash@^4'));
    }

    public function testParsePerEcosystem(): void
    {
        [$name, $range, $build] = VersionRange::parse(Ecosystem::Npm, '@angular/core@>=16 <19 [toutes]');
        self::assertSame(['@angular/core', '>=16 <19', '@angular/core@16.0.0'], [$name, $range, $build('16.0.0')]);

        [$name, $range] = VersionRange::parse(Ecosystem::Npm, 'react [toutes]');
        self::assertSame(['react', '*'], [$name, $range]);

        [$name, $constraint, $build] = VersionRange::parse(Ecosystem::Composer, 'symfony/console:>=6.4 <8 [toutes]');
        self::assertSame(['symfony/console', '>=6.4 <8', 'symfony/console:v7.0.0'], [$name, $constraint, $build('v7.0.0')]);

        [$name, $specifier, $build] = VersionRange::parse(Ecosystem::Pypi, 'Django[argon2]>=4,<6 [toutes]');
        self::assertSame(['Django', '>=4,<6', 'Django[argon2]==4.2.1'], [$name, $specifier, $build('4.2.1')]);
    }

    public function testUnsupportedEcosystem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VersionRange::parse(Ecosystem::Docker, 'nginx:1 [toutes]');
    }

    public function testComposerMatchingKeepsStableVersionsInOrder(): void
    {
        $versions = (new VersionFilter('/opt/pip/bin/pip'))->matching(Ecosystem::Composer, ['v8.0.0', 'v7.1.0', 'v6.3.9', 'v6.4.0', 'v7.0.0-RC1', 'dev-main', 'v7.0.0'], '>=6.4 <8');
        self::assertSame(['v6.4.0', 'v7.0.0', 'v7.1.0'], $versions);
    }

    public function testNpmMatchingWithNpmSemver(): void
    {
        if (!is_dir('/usr/lib/node_modules/npm/node_modules/semver')) {
            self::markTestSkipped('npm absent.');
        }
        $filter = new VersionFilter('/opt/pip/bin/pip');
        $versions = $filter->matching(Ecosystem::Npm, ['19.0.0', '18.3.1', '16.0.0', '15.7.0', '17.0.2', '18.0.0-rc.0', '16.14.0'], '>=16 <19');
        self::assertSame(['16.0.0', '16.14.0', '17.0.2', '18.3.1'], $versions);
        self::assertSame(['16.0.0', '16.14.0', '17.0.2', '18.3.1'], $filter->matching(Ecosystem::Npm, ['16.0.0', '16.14.0', '17.0.2', '18.3.1', '19.0.0'], '16 - 18'));

        $this->expectException(\InvalidArgumentException::class);
        $filter->matching(Ecosystem::Npm, ['1.0.0'], 'next');
    }

    public function testPythonMatchingWithPipPackaging(): void
    {
        if (!is_file('/opt/pip/bin/python')) {
            self::markTestSkipped('pip absent.');
        }
        $versions = (new VersionFilter('/opt/pip/bin/pip'))->matching(Ecosystem::Pypi, ['5.2', '4.2.10', '3.2', '4.0', '5.0rc1', '6.0', '4.2.9'], '>=4,<6');
        self::assertSame(['4.0', '4.2.9', '4.2.10', '5.2'], $versions);
    }
}
