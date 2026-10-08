<?php

namespace App\Tests\Functional;

use App\Entity\Job;
use App\Tests\MirrorWebTestCase;
use App\Tests\Support\BundleBuilder;
use Sas\Bundle\Signer;

/** Recettes Symfony Flex servies par Romulus (SYMFONY_ENDPOINT=http://miroir/composer/flex). */
final class FlexEndpointTest extends MirrorWebTestCase
{
    public function testServesRecipesInFlexFormat(): void
    {
        $keys = Signer::generateKeyPair();
        $builder = new BundleBuilder($this->dir.'/source');
        $links = ['repository' => 'github.com/symfony/recipes', 'originTemplate' => '{package}:{version}@github.com/symfony/recipes:main', 'branch' => 'main', 'isContrib' => false];
        $index = $builder->blob((string) json_encode([
            'recipes' => ['symfony/console' => ['3.3', '5.3'], 'symfony/framework-bundle' => ['7.4', '8.1']],
            'versions' => ['stable' => '8.1', 'lts' => '7.4'],
            'aliases' => ['console' => 'symfony/console'],
            '_links' => ['recipe_template' => 'https://example.invalid/{package_dotted}.{version}.json'],
        ]));
        $contrib = $builder->blob((string) json_encode([
            'recipes' => ['acme/bundle' => ['1.0']],
            'versions' => ['stable' => 'ignorée'],
            'aliases' => ['acme' => 'acme/bundle', 'console' => 'pas/prioritaire'],
        ]));
        $console = $builder->blob((string) json_encode(['manifests' => ['symfony/console' => [
            'manifest' => ['copy-from-recipe' => ['bin/' => '%BIN_DIR%/']],
            'files' => ['bin/console' => ['contents' => ['#!/usr/bin/env php', '<?php'], 'executable' => true]],
            'ref' => 'abc123',
        ]]]));
        $framework = $builder->blob((string) json_encode(['manifests' => ['symfony/framework-bundle' => [
            'manifest' => ['bundles' => ['Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle' => ['all']]],
            'files' => ['config/services.yaml' => ['contents' => base64_encode("services:\n"), 'executable' => false]],
            'ref' => 'def456',
        ]]]));
        $builder
            ->artifact('composer', '_flex/index', 'recipes', null, [$index], $links)
            ->artifact('composer', '_flex/index', 'recipes-contrib', null, [$contrib], ['isContrib' => true] + $links)
            ->artifact('composer', '_flex/symfony/console', '5.3', 'recipes', [$console], $links)
            ->artifact('composer', '_flex/symfony/framework-bundle', '8.1', 'recipes', [$framework], $links);

        $this->trust($keys['publicKey']);
        $this->login();
        self::assertSame(Job::SUCCEEDED, $this->import($builder->write($this->dir.'/inbox', $keys))->getStatus());

        // Index fusionnés : les recettes officielles l'emportent sur contrib
        $this->client->request('GET', '/composer/flex/versions.json');
        self::assertSame(['stable' => '8.1', 'lts' => '7.4'], $this->json());
        $this->client->request('GET', '/composer/flex/aliases.json');
        self::assertSame(['console' => 'symfony/console', 'acme' => 'acme/bundle'], $this->json());

        // Requête de Flex : vendeur,nom,<opération><version>[,date] séparés par « ; »
        $this->client->request('GET', '/composer/flex/p/symfony,console,iv8.1.2,1700000000;symfony,framework-bundle,i8.1.0;inconnu,paquet,i1.0.0');
        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertSame(['symfony/console', 'symfony/framework-bundle'], array_keys($data['manifests']));

        $console = $data['manifests']['symfony/console'];
        self::assertSame("#!/usr/bin/env php\n<?php", $console['files']['bin/console']['contents']);
        self::assertSame('5.3', $console['version']);
        self::assertSame('symfony/console:5.3@github.com/symfony/recipes:main', $console['origin']);
        self::assertFalse($console['is_contrib']);
        self::assertSame("services:\n", $data['manifests']['symfony/framework-bundle']['files']['config/services.yaml']['contents']);
        self::assertSame(['version' => '8.1', 'recipe' => ['repo' => 'github.com/symfony/recipes', 'branch' => 'main', 'version' => '5.3', 'ref' => 'abc123']], $data['locks']['symfony/console']);

        // Les recettes ne sont pas des paquets Composer
        $this->client->request('GET', '/composer/packages.json');
        self::assertNotContains('_flex/index', $this->json()['available-packages']);
    }
}
