<?php

namespace App\Tests\Unit\Template;

use App\Resolver\FlexRecipes;
use App\Template\TemplateCatalog;
use PHPUnit\Framework\TestCase;
use Sas\Bundle\Ecosystem;

final class TemplatesTest extends TestCase
{
    public function testRecipeVersionFollowsFlexRule(): void
    {
        $versions = ['3.3', '4.4', '5.3'];
        self::assertSame('5.3', FlexRecipes::recipeVersion('v8.1.2', $versions));
        self::assertSame('4.4', FlexRecipes::recipeVersion('4.4.10', $versions));
        self::assertSame('3.3', FlexRecipes::recipeVersion('4.0.0', $versions));
        self::assertNull(FlexRecipes::recipeVersion('2.8.0', $versions));
        // Branche sans alias : Flex non plus ne trouve pas de recette (il utilise l'alias quand il existe)
        self::assertNull(FlexRecipes::recipeVersion('dev-main', $versions));
        self::assertSame('5.3', FlexRecipes::recipeVersion('6.0.x-dev', $versions));
    }

    public function testTemplateValidation(): void
    {
        self::assertSame([], TemplateCatalog::validate(Ecosystem::Npm, null));
        self::assertSame([], TemplateCatalog::validate(Ecosystem::Npm, ['id' => 'angular', 'version' => '22']));
        self::assertSame([], TemplateCatalog::validate(Ecosystem::Composer, ['id' => 'symfony', 'version' => '8.1']));
        self::assertSame([], TemplateCatalog::validate(Ecosystem::Npm, ['id' => 'vite-react', 'version' => 'latest']));
        // Mauvais écosystème, liste prédéfinie (non exécutable), version invalide
        self::assertNotEmpty(TemplateCatalog::validate(Ecosystem::Composer, ['id' => 'angular', 'version' => '22']));
        self::assertNotEmpty(TemplateCatalog::validate(Ecosystem::Pypi, ['id' => 'django', 'version' => '5']));
        self::assertNotEmpty(TemplateCatalog::validate(Ecosystem::Npm, ['id' => 'angular', 'version' => '22; rm -rf /']));
    }

    public function testEveryTemplateIsComplete(): void
    {
        foreach (TemplateCatalog::all() as $template) {
            self::assertNotNull(Ecosystem::tryFrom($template['ecosystem']), $template['id']);
            if ('generator' === $template['kind']) {
                self::assertArrayHasKey('usage', $template, $template['id']);
                self::assertArrayHasKey('version', $template, $template['id']);
            } else {
                self::assertNotEmpty($template['specs'], $template['id']);
            }
        }
    }
}
