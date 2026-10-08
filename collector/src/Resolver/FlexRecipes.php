<?php

namespace App\Resolver;

use App\Entity\Artifact;
use App\Http\DownloadException;
use App\Http\Downloader;
use App\Job\JobContext;
use Sas\Bundle\BlobStore;
use Sas\Bundle\Ecosystem;

/**
 * Recettes Symfony Flex : Flex les télécharge depuis GitHub (hors de Composer) à chaque installation
 * d'un paquet. On collecte les index (recettes officielles et contrib) et la recette de chaque paquet,
 * que Romulus sert ensuite sous /composer/flex.
 *
 * Artefacts Composer au nom réservé (un nom de paquet ne commence jamais par « _ ») :
 *   _flex/index           version = dépôt (recipes, recipes-contrib)
 *   _flex/<paquet>        version = version de la recette, fichier = dépôt
 */
final readonly class FlexRecipes
{
    public const array REPOSITORIES = ['recipes', 'recipes-contrib'];

    public function __construct(
        private Downloader $downloader,
        private ArtifactCatalog $catalog,
        private BlobStore $store,
    ) {
    }

    /** Version de recette retenue par Flex pour une version de paquet (même règle que Flex). */
    public static function recipeVersion(string $packageVersion, array $recipeVersions): ?string
    {
        $parts = explode('.', (string) preg_replace('/^dev-|^v|\.x-dev$|-dev$/', '', $packageVersion));
        $version = $parts[0].'.'.($parts[1] ?? '9999999');
        foreach (array_reverse($recipeVersions) as $candidate) {
            if (version_compare($version, (string) $candidate, '>=')) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $packages nom => version des paquets Composer collectés
     *
     * @return list<Artifact>
     */
    public function collect(array $packages, JobContext $context): array
    {
        $context->report('Recettes Symfony Flex');
        $artifacts = [];
        $indexes = [];
        foreach (self::REPOSITORIES as $repository) {
            $url = sprintf('https://raw.githubusercontent.com/symfony/%s/flex/main/index.json', $repository);
            $blob = $this->downloader->fetch($url);
            $index = json_decode($this->store->read($blob['sha256']), true);
            if (!\is_array($index) || !isset($index['recipes'], $index['_links'])) {
                throw new \RuntimeException(sprintf('Index des recettes Flex illisible (%s).', $repository));
            }
            $indexes[$repository] = $index;
            $artifacts[] = $this->catalog->record(Ecosystem::Composer, '_flex/index', $repository, null, [$blob], self::links($index));
        }

        $count = 0;
        foreach ($packages as $name => $version) {
            foreach ($indexes as $repository => $index) {
                // Comme Flex : le premier dépôt qui connaît le paquet l'emporte
                if (!isset($index['recipes'][$name])) {
                    continue;
                }
                $recipe = self::recipeVersion($version, $index['recipes'][$name]);
                if (null === $recipe) {
                    break;
                }
                $url = strtr($index['_links']['recipe_template'], [
                    '{package_dotted}' => str_replace('/', '.', $name),
                    '{package}' => $name,
                    '{version}' => $recipe,
                ]);
                try {
                    $blob = $this->downloader->fetch($url);
                } catch (DownloadException $e) {
                    $context->log(sprintf('Recette Flex introuvable pour %s %s : %s', $name, $recipe, $e->getMessage()));
                    break;
                }
                $artifacts[] = $this->catalog->record(Ecosystem::Composer, '_flex/'.$name, $recipe, $repository, [$blob], self::links($index));
                ++$count;
                break;
            }
        }
        $this->catalog->flush();
        $context->log(sprintf('%d recette(s) Symfony Flex.', $count));

        return $artifacts;
    }

    /**
     * @param array<string, mixed> $index
     *
     * @return array<string, mixed>
     */
    private static function links(array $index): array
    {
        return [
            'repository' => $index['_links']['repository'] ?? null,
            'originTemplate' => $index['_links']['origin_template'] ?? null,
            'branch' => $index['branch'] ?? 'main',
            'isContrib' => (bool) ($index['is_contrib'] ?? false),
        ];
    }
}
