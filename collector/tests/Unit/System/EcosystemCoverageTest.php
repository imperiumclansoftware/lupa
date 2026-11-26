<?php

namespace App\Tests\Unit\System;

use App\Controller\BlacklistController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sas\Bundle\Ecosystem;
use Sas\Bundle\PackageKey;

/**
 * Identité des paquets et validation des versions de la liste noire : chaque écosystème a une règle explicite.
 * Un écosystème ajouté sans branche lève UnhandledMatchError ici, même sans PHPStan.
 */
final class EcosystemCoverageTest extends TestCase
{
    /** @return iterable<string, array{Ecosystem}> */
    public static function ecosystems(): iterable
    {
        foreach (Ecosystem::cases() as $ecosystem) {
            yield $ecosystem->value => [$ecosystem];
        }
    }

    #[DataProvider('ecosystems')]
    public function testEveryEcosystemHasExplicitRules(Ecosystem $ecosystem): void
    {
        self::assertNotSame('', PackageKey::of($ecosystem, 'Exemple', '1.2.3'));
        self::assertNull(BlacklistController::validate($ecosystem, 'exemple', '1.2.3-1'));
        self::assertNotNull(BlacklistController::validate($ecosystem, 'exemple', 'latest'));
    }

    /** Comportement inchangé pour les écosystèmes qui passaient par la branche par défaut */
    public function testFormerDefaultsAreUnchanged(): void
    {
        self::assertSame(PackageKey::of(Ecosystem::Vscode, 'redhat.vscode-yaml', '1.15.0'), PackageKey::of(Ecosystem::Vscode, 'RedHat.vscode-yaml', '1.15.0'));
        self::assertSame(PackageKey::of(Ecosystem::Npm, '@Angular/Core', '20.0.0'), PackageKey::of(Ecosystem::Npm, '@angular/core', '20.0.0'));
        self::assertNotSame(PackageKey::of(Ecosystem::Npm, 'left-pad', 'v1.3.0'), PackageKey::of(Ecosystem::Npm, 'left-pad', '1.3.0'));
        self::assertNull(BlacklistController::validate(Ecosystem::Npm, 'left-pad', '1.3.0-beta.1'));
        self::assertNotNull(BlacklistController::validate(Ecosystem::Npm, 'left-pad', '^1.3.0'));
        self::assertStringContainsString('1.2.3', (string) BlacklistController::validate(Ecosystem::Vscode, 'redhat.vscode-yaml', '1.x'));
    }
}
