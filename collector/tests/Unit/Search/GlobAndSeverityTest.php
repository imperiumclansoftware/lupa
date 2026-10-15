<?php

namespace App\Tests\Unit\Search;

use App\Audit\Severity;
use App\Search\Glob;
use PHPUnit\Framework\TestCase;

final class GlobAndSeverityTest extends TestCase
{
    public function testGlobPatterns(): void
    {
        self::assertTrue(Glob::isPattern('@syncfusion/ej2-*angular*'));
        self::assertFalse(Glob::isPattern('lodash@^4'));
        self::assertTrue(Glob::matches('@syncfusion/ej2-*angular*', '@syncfusion/ej2-angular-grids'));
        self::assertFalse(Glob::matches('@syncfusion/ej2-*angular*', '@syncfusion/ej2-grids'));
        self::assertTrue(Glob::matches('python:3.1?-slim', 'python:3.12-slim'));
        self::assertFalse(Glob::matches('python:3.1?-slim', 'python:3.1-slim'));
        // Les caractères spéciaux des expressions régulières restent littéraux
        self::assertFalse(Glob::matches('a.b*', 'axb-c'));
        self::assertSame('24-', Glob::filter('24-*alpine'));
        self::assertSame('-exporter', Glob::filter('*-exporter'));
        self::assertSame(['angular', 'ej2-'], Glob::literals('ej2-*angular*'));
    }

    public function testCvss3BaseScores(): void
    {
        // Valeurs de référence du calculateur FIRST CVSS 3.1
        self::assertSame(9.8, Severity::cvss3('CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H'));
        self::assertSame(7.4, Severity::cvss3('CVSS:3.1/AV:N/AC:H/PR:N/UI:N/S:U/C:N/I:H/A:H'));
        self::assertSame(6.1, Severity::cvss3('CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:C/C:L/I:L/A:N'));
        self::assertSame(10.0, Severity::cvss3('CVSS:3.0/AV:N/AC:L/PR:N/UI:N/S:C/C:H/I:H/A:H'));
        self::assertSame(0.0, Severity::cvss3('CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:N'));
        self::assertNull(Severity::cvss3('CVSS:4.0/AV:N/AC:L/AT:N/PR:N/UI:N/VC:H/VI:H/VA:H/SC:N/SI:N/SA:N'));
    }

    public function testSeverityOfAdvisory(): void
    {
        self::assertSame('high', Severity::of(['database_specific' => ['severity' => 'HIGH']]));
        self::assertSame('moderate', Severity::of(['database_specific' => ['severity' => 'MODERATE']]));
        self::assertSame('critical', Severity::of(['severity' => [['type' => 'CVSS_V3', 'score' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H']]]));
        self::assertSame('unknown', Severity::of(['severity' => [['type' => 'CVSS_V4', 'score' => 'CVSS:4.0/AV:N']]]));
        self::assertSame('critical', Severity::max('high', 'critical'));
        self::assertSame('low', Severity::max(null, 'low'));
    }
}
