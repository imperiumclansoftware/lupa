<?php

namespace App\Controller;

use App\Entity\Package;
use App\Registry\Files;
use App\Repository\PackageRepository;
use Doctrine\DBAL\Connection;
use Sas\Bundle\Ecosystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dépôt Composer (format « p2 » de Composer 2). Configuration côté client :
 *   composer config repositories.sas composer http://miroir/composer
 *   composer config repositories.packagist false
 *   composer config secure-http false   (si le miroir est en HTTP)
 */
final class ComposerRegistryController
{
    public function __construct(
        private readonly PackageRepository $packages,
        private readonly Connection $db,
        private readonly Files $files,
    ) {
    }

    #[Route('/composer/packages.json', methods: ['GET', 'HEAD'])]
    public function root(Request $request): JsonResponse
    {
        $names = $this->db->fetchFirstColumn("SELECT DISTINCT name FROM package WHERE ecosystem = 'composer' ORDER BY name");

        return new JsonResponse([
            'packages' => new \stdClass(),
            'metadata-url' => '/composer/p2/%package%.json',
            // Évite à Composer de chercher des paquets qui n'existent pas ici
            'available-packages' => $names,
        ]);
    }

    #[Route('/composer/p2/{vendor}/{package}.json', methods: ['GET', 'HEAD'], requirements: ['vendor' => '[a-z0-9_.-]+', 'package' => '[a-z0-9_.-]+(~dev)?'])]
    public function metadata(Request $request, string $vendor, string $package): Response
    {
        $dev = str_ends_with($package, '~dev');
        $name = $vendor.'/'.($dev ? substr($package, 0, -4) : $package);
        $base = $this->files->baseUrl($request);

        $versions = [];
        /** @var Package $p */
        foreach ($this->packages->findBy(['ecosystem' => Ecosystem::Composer, 'name' => $name]) as $p) {
            $isDev = str_starts_with($p->getVersion(), 'dev-') || str_ends_with($p->getVersion(), '-dev');
            if ($isDev !== $dev) {
                continue;
            }
            $data = $p->getMetadata();
            $distType = $data['distType'] ?? 'zip';
            $reference = $data['distReference'] ?? null;
            unset($data['distType'], $data['distReference']);
            if ([] !== $p->getBlobs()) {
                $sha = $p->getBlobs()[0];
                $data['dist'] = [
                    'type' => $distType,
                    'url' => sprintf('%s/composer/dist/%s/%s-%s.%s', $base, $sha, str_replace('/', '-', $name), preg_replace('/[^A-Za-z0-9._-]/', '_', $p->getVersion()), 'zip' === $distType ? 'zip' : 'tar'),
                    'reference' => $reference,
                    'shasum' => '',
                ];
            }
            $versions[] = $data;
        }
        if ([] === $versions && !$dev) {
            return new JsonResponse(['error' => 'Paquet absent du miroir hors ligne.'], 404);
        }

        return new JsonResponse(['packages' => [$name => $versions]]);
    }

    #[Route('/composer/dist/{sha256}/{filename}', methods: ['GET', 'HEAD'], requirements: ['sha256' => '[a-f0-9]{64}', 'filename' => '[^/]+'])]
    public function dist(string $sha256): Response
    {
        return $this->files->serve($sha256);
    }
}
