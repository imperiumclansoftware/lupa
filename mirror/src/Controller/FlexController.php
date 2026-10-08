<?php

namespace App\Controller;

use App\Registry\Files;
use App\Repository\PackageRepository;
use Sas\Bundle\Ecosystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recettes Symfony Flex, au format du « point d'accès » de Flex. Configuration côté poste :
 *   export SYMFONY_ENDPOINT=http://miroir/composer/flex
 * Flex n'interroge alors plus GitHub : composer create-project symfony/skeleton, composer require webapp…
 * fonctionnent hors ligne.
 *
 * Flex demande versions.json, aliases.json, puis /p/<paquets> où chaque paquet est décrit par
 * « vendeur,nom,<opération><version>[,date] », séparés par « ; ».
 */
#[Route('/composer/flex')]
final class FlexController
{
    /** @var array<string, array<string, mixed>>|null index par dépôt (recipes, recipes-contrib) */
    private ?array $indexes = null;

    public function __construct(
        private readonly PackageRepository $packages,
        private readonly Files $files,
    ) {
    }

    #[Route('/versions.json', methods: ['GET', 'HEAD'])]
    public function versions(): JsonResponse
    {
        return new JsonResponse((object) $this->merged('versions'));
    }

    #[Route('/aliases.json', methods: ['GET', 'HEAD'])]
    public function aliases(): JsonResponse
    {
        return new JsonResponse((object) $this->merged('aliases'));
    }

    #[Route('/p/{chunk}', requirements: ['chunk' => '.+'], methods: ['GET', 'HEAD'])]
    public function recipes(string $chunk): JsonResponse
    {
        $manifests = [];
        $locks = [];
        foreach (explode(';', $chunk) as $entry) {
            $parts = explode(',', $entry);
            if (\count($parts) < 3 || '' === $parts[2]) {
                continue;
            }
            $name = $parts[0].'/'.$parts[1];
            $version = substr($parts[2], 1);
            [$repository, $recipeVersion] = $this->locate($name, $version) ?? [null, null];
            if (null === $repository) {
                continue;
            }

            $package = $this->packages->findOneBy([
                'ecosystem' => Ecosystem::Composer, 'name' => '_flex/'.$name, 'version' => $recipeVersion, 'filename' => $repository,
            ]);
            if (!$package || [] === $package->getBlobs() || !$this->files->has($package->getBlobs()[0])) {
                continue;
            }
            $manifest = json_decode($this->files->read($package->getBlobs()[0]), true)['manifests'][$name] ?? null;
            if (!\is_array($manifest)) {
                continue;
            }
            $links = $package->getMetadata();

            // Même transformation que Flex applique aux recettes téléchargées depuis GitHub
            foreach ($manifest['files'] ?? [] as $i => $file) {
                $manifest['files'][$i]['contents'] = \is_array($file['contents']) ? implode("\n", $file['contents']) : base64_decode((string) $file['contents']);
            }
            $manifests[$name] = $manifest + [
                'repository' => $links['repository'],
                'package' => $name,
                'version' => $recipeVersion,
                'origin' => strtr((string) $links['originTemplate'], ['{package}' => $name, '{version}' => $recipeVersion]),
                'is_contrib' => (bool) $links['isContrib'],
            ];
            $locks[$name] = [
                'version' => self::shortVersion($version),
                'recipe' => [
                    'repo' => $links['repository'],
                    'branch' => $links['branch'],
                    'version' => $recipeVersion,
                    'ref' => $manifest['ref'] ?? null,
                ],
            ];
        }

        return new JsonResponse(['manifests' => (object) $manifests, 'locks' => (object) $locks]);
    }

    /** @return array{string, string}|null dépôt et version de la recette */
    private function locate(string $name, string $version): ?array
    {
        foreach ($this->indexes() as $repository => $index) {
            if (!isset($index['recipes'][$name])) {
                continue;
            }
            $short = self::shortVersion($version);
            foreach (array_reverse($index['recipes'][$name]) as $candidate) {
                if (version_compare($short, (string) $candidate, '>=')) {
                    return [$repository, (string) $candidate];
                }
            }

            return null;
        }

        return null;
    }

    /** « v7.4.2 » → « 7.4 », comme Flex. */
    private static function shortVersion(string $version): string
    {
        $parts = explode('.', (string) preg_replace('/^dev-|^v|\.x-dev$|-dev$/', '', $version));

        return $parts[0].'.'.($parts[1] ?? '9999999');
    }

    /** @return array<string, mixed> clé fusionnée des index (recettes officielles prioritaires) */
    private function merged(string $key): array
    {
        $merged = [];
        foreach ($this->indexes() as $index) {
            $merged += (array) ($index[$key] ?? []);
        }

        return $merged;
    }

    /** @return array<string, array<string, mixed>> */
    private function indexes(): array
    {
        if (null === $this->indexes) {
            $this->indexes = [];
            foreach (['recipes', 'recipes-contrib'] as $repository) {
                $package = $this->packages->findOneBy(['ecosystem' => Ecosystem::Composer, 'name' => '_flex/index', 'version' => $repository]);
                if ($package && [] !== $package->getBlobs() && $this->files->has($package->getBlobs()[0])) {
                    $this->indexes[$repository] = json_decode($this->files->read($package->getBlobs()[0]), true) ?: [];
                }
            }
        }

        return $this->indexes;
    }
}
