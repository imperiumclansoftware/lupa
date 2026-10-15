<?php

namespace App\Tests\Unit\Search;

use App\Search\VersionFilter;
use PHPUnit\Framework\TestCase;
use Sas\Bundle\Ecosystem;

/** Filtrage d'un motif par contrainte, avec les vrais outils de chaque écosystème (sans réseau). */
final class VersionFilterTest extends TestCase
{
    public function testComposerConstraint(): void
    {
        $filter = new VersionFilter('/opt/pip/bin/pip');
        $result = $filter->filter(Ecosystem::Composer, [
            'symfony/framework-bundle' => ['v7.3.1', 'v8.0.0'],
            'symfony/acl-bundle' => ['v2.4.0', 'dev-main'],
            'symfony/beta-bundle' => ['v7.3.0-BETA1'],
        ], '^7.3');
        self::assertSame(['symfony/framework-bundle'], $result['keep']);
        self::assertSame(['symfony/acl-bundle', 'symfony/beta-bundle'], $result['skipped']);
    }

    public function testNpmRangeWithNpmSemver(): void
    {
        if (!is_dir('/usr/lib/node_modules/npm/node_modules/semver')) {
            self::markTestSkipped('npm absent.');
        }
        $result = (new VersionFilter('/opt/pip/bin/pip'))->filter(Ecosystem::Npm, [
            '@syncfusion/ej2-angular-grids' => ['28.2.3', '29.1.33', '30.1.37'],
            '@syncfusion/ej2-angular-a2ui' => ['33.1.44'],
            'vide' => [],
        ], '^29');
        self::assertSame(['@syncfusion/ej2-angular-grids'], $result['keep']);
        self::assertSame(['@syncfusion/ej2-angular-a2ui', 'vide'], $result['skipped']);
    }

    public function testPythonSpecifierWithPipPackaging(): void
    {
        if (!is_file('/opt/pip/bin/python')) {
            self::markTestSkipped('pip absent.');
        }
        $result = (new VersionFilter('/opt/pip/bin/pip'))->filter(Ecosystem::Pypi, [
            'django-rest-framework' => ['2.4.0', '3.15.2'],
            'django-rest-old' => ['0.1', '1.0'],
            'django-rest-pre' => ['3.0rc1'],
        ], '>=3');
        // Préversions exclues, comme pip par défaut
        self::assertSame(['django-rest-framework'], $result['keep']);
    }
}
