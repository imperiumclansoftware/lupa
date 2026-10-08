<?php

namespace App\Controller;

use App\Registry\Files;
use App\Repository\OciManifestRepository;
use App\Repository\PackageRepository;
use Doctrine\DBAL\Connection;
use Sas\Bundle\Ecosystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Registre Docker en lecture seule (API OCI Distribution). Utilisation :
 *   docker pull miroir:8090/library/nginx:1.27     (ou miroir:8090/nginx:1.27)
 * ou, pour garder « docker pull nginx:1.27 », déclarer le miroir dans /etc/docker/daemon.json :
 *   { "registry-mirrors": ["http://miroir:8090"], "insecure-registries": ["miroir:8090"] }
 */
final class DockerRegistryController
{
    public function __construct(
        private readonly PackageRepository $packages,
        private readonly OciManifestRepository $manifests,
        private readonly Connection $db,
        private readonly Files $files,
    ) {
    }

    #[Route('/v2/{path}', requirements: ['path' => '.*'], defaults: ['path' => ''], methods: ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'])]
    public function __invoke(Request $request, string $path): Response
    {
        $response = $this->handle($request, trim($path, '/'));
        $response->headers->set('Docker-Distribution-API-Version', 'registry/2.0');

        return $response;
    }

    private function handle(Request $request, string $path): Response
    {
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return self::error('UNSUPPORTED', 'Miroir en lecture seule : l\'envoi d\'images (push) n\'est pas possible.', 405);
        }
        if ('' === $path) {
            return new JsonResponse(new \stdClass());
        }
        if ('_catalog' === $path) {
            return new JsonResponse(['repositories' => $this->db->fetchFirstColumn("SELECT DISTINCT name FROM package WHERE ecosystem = 'docker' ORDER BY name")]);
        }
        if (preg_match('#^(.+)/tags/list$#', $path, $m)) {
            $name = $this->repository($m[1]);
            if (null === $name) {
                return self::error('NAME_UNKNOWN', 'Dépôt absent du miroir.', 404);
            }
            $tags = $this->db->fetchFirstColumn("SELECT version FROM package WHERE ecosystem = 'docker' AND name = ? AND version NOT LIKE 'sha256:%' ORDER BY version", [$name]);

            return new JsonResponse(['name' => $m[1], 'tags' => $tags]);
        }
        if (preg_match('#^(.+)/manifests/([^/]+)$#', $path, $m)) {
            return $this->manifest($m[1], $m[2]);
        }
        if (preg_match('#^(.+)/blobs/(sha256:([a-f0-9]{64}))$#', $path, $m)) {
            if (null === $this->repository($m[1]) || !$this->files->has($m[3])) {
                return self::error('BLOB_UNKNOWN', 'Blob absent du miroir.', 404);
            }
            $response = $this->files->serve($m[3]);
            $response->headers->set('Docker-Content-Digest', $m[2]);

            return $response;
        }

        return self::error('UNSUPPORTED', 'Requête non prise en charge.', 404);
    }

    private function manifest(string $repository, string $reference): Response
    {
        $name = $this->repository($repository);
        if (null === $name) {
            return self::error('NAME_UNKNOWN', sprintf('Image %s absente du miroir hors ligne.', $repository), 404);
        }

        if (preg_match('/^sha256:[a-f0-9]{64}$/', $reference)) {
            $manifest = $this->manifests->find($reference);
            if (!$manifest || !$this->files->has($manifest->sha256())) {
                return self::error('MANIFEST_UNKNOWN', 'Manifeste absent du miroir (plateforme non collectée ?).', 404);
            }
            [$digest, $mediaType] = [$reference, $manifest->getMediaType()];
        } else {
            $package = $this->packages->findOneBy(['ecosystem' => Ecosystem::Docker, 'name' => $name, 'version' => $reference]);
            if (!$package) {
                return self::error('MANIFEST_UNKNOWN', sprintf('Étiquette %s:%s absente du miroir hors ligne.', $repository, $reference), 404);
            }
            $digest = (string) $package->getMetadata()['digest'];
            $mediaType = (string) $package->getMetadata()['mediaType'];
        }

        $response = $this->files->serve(substr($digest, 7), $mediaType);
        $response->headers->set('Docker-Content-Digest', $digest);
        // Une étiquette peut être déplacée par un import ultérieur : pas de cache immuable
        if (!str_starts_with($reference, 'sha256:')) {
            $response->setMaxAge(0);
            $response->headers->removeCacheControlDirective('immutable');
            $response->headers->addCacheControlDirective('no-cache');
        }

        return $response;
    }

    /** Nom réel du dépôt : « nginx » désigne « library/nginx », comme sur Docker Hub. */
    private function repository(string $name): ?string
    {
        foreach (str_contains($name, '/') ? [$name] : [$name, 'library/'.$name] as $candidate) {
            if (false !== $this->db->fetchOne("SELECT 1 FROM package WHERE ecosystem = 'docker' AND name = ? LIMIT 1", [$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    private static function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['errors' => [['code' => $code, 'message' => $message, 'detail' => null]]], $status);
    }
}
