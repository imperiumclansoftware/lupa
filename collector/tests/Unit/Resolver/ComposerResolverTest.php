<?php

namespace App\Tests\Unit\Resolver;

use App\Resolver\ComposerResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComposerResolverTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function specs(): iterable
    {
        yield 'deux-points' => ['a/b:^1', 'a/b', '^1'];
        yield 'espace' => ['a/b ^1', 'a/b', '^1'];
        yield 'sans contrainte' => ['a/b', 'a/b', '*'];
        yield 'espaces autour' => ['  monolog/monolog   ^3.0  ', 'monolog/monolog', '^3.0'];
        yield 'majuscules' => ['Symfony/Console:^7.0', 'symfony/console', '^7.0'];
        yield 'contrainte composée' => ['a/b:>=1.0 <2.0', 'a/b', '>=1.0 <2.0'];
        yield 'deux-points vide' => ['a/b:', 'a/b', '*'];
        yield 'branche' => ['a/b:dev-main', 'a/b', 'dev-main'];
    }

    #[DataProvider('specs')]
    public function testParseSpec(string $line, string $name, string $constraint): void
    {
        self::assertSame([$name, $constraint], ComposerResolver::parseSpec($line));
    }
}
