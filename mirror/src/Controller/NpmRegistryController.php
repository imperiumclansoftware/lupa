<?php

namespace App\Controller;

use App\Entity\Package;
use App\Quarantine\Quarantine;
use App\Registry\Files;
use App\Registry\Semver;
use App\Repository\PackageRepository;
use Sas\Bundle\Ecosystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Registre npm en lecture seule. Configuration côté client :
 *   npm config set registry http://miroir/npm/
 */
final class NpmRegistryController
{
    private const string NAME = '(@[^/]+/[^/@][^/]*|[^/@][^/]*)';

    public function __construct(
        private readonly PackageRepository $packages,
        private readonly Files $files,
        private readonly Quarantine $quarantine,
    ) {
    }

    #[Route('/npm/{path}', requirements: ['path' => '.*'], methods: ['GET', 'HEAD', 'POST'], defaults: ['path' => ''])]
    public function __invoke(Request $request, string $path): Response
    {
        $path = trim($path, '/');

        // Points d'accès de service : audit et recherche n'ont pas de sens hors ligne, on répond « rien »
        if ('' === $path || str_starts_with($path, '-/')) {
            return match (true) {
                str_starts_with($path, '-/v1/search') => new JsonResponse(['objects' => [], 'total' => 0, 'time' => gmdate(\DATE_ATOM)]),
                default => new JsonResponse(new \stdClass()),
            };
        }

        if (preg_match('#^'.self::NAME.'/-/([^/]+\.tgz)$#', $path, $m)) {
            return $this->tarball($m[1], $m[2]);
        }
        if (preg_match('#^'.self::NAME.'$#', $path, $m)) {
            return $this->packument($request, $m[1]);
        }
        if (preg_match('#^'.self::NAME.'/([^/]+)$#', $path, $m)) {
            return $this->version($request, $m[1], $m[2]);
        }

        return self::notFound();
    }

    private function packument(Request $request, string $name): Response
    {
        $versions = $this->versions($request, $name);
        if ([] === $versions) {
            // Paquet présent mais toutes ses versions en quarantaine : le dire plutôt que « absent »
            foreach ($this->packages->findBy(['ecosystem' => Ecosystem::Npm, 'name' => $name], limit: 1) as $package) {
                return self::quarantined($name, $package->getVersion(), $this->quarantine->forPackage($package)?->getReason() ?? '');
            }

            return self::notFound();
        }

        $stable = array_filter(array_keys($versions), static fn ($v) => !Semver::isPrerelease((string) $v));
        $latest = end($stable) ?: array_key_last($versions);
        $times = ['created' => null, 'modified' => null];
        foreach ($versions as $version => $document) {
            $times[$version] = $document['_mirroredAt'];
            $times['created'] ??= $document['_mirroredAt'];
            $times['modified'] = max($times['modified'] ?? '', $document['_mirroredAt']);
        }
        $versions = array_map(static function (array $d) {
            unset($d['_mirroredAt']);

            return $d;
        }, $versions);

        $response = new JsonResponse([
            '_id' => $name,
            'name' => $name,
            'dist-tags' => ['latest' => (string) $latest],
            'versions' => $versions,
            'time' => $times,
        ]);
        $response->setEtag(sha1((string) $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }

    private function version(Request $request, string $name, string $version): Response
    {
        $versions = $this->versions($request, $name);
        if ('latest' === $version && [] !== $versions) {
            $stable = array_filter(array_keys($versions), static fn ($v) => !Semver::isPrerelease((string) $v));
            $version = (string) (end($stable) ?: array_key_last($versions));
        }
        if (!isset($versions[$version])) {
            return null !== ($entry = $this->quarantine->find(Ecosystem::Npm, $name, $version)) ? self::quarantined($name, $version, $entry->getReason()) : self::notFound();
        }
        unset($versions[$version]['_mirroredAt']);

        return new JsonResponse($versions[$version]);
    }

    private function tarball(string $name, string $file): Response
    {
        foreach ($this->packages->findBy(['ecosystem' => Ecosystem::Npm, 'name' => $name]) as $package) {
            if (($package->getMetadata()['dist']['tarballName'] ?? null) === $file && [] !== $package->getBlobs()) {
                if ($entry = $this->quarantine->forPackage($package)) {
                    return self::quarantined($name, $package->getVersion(), $entry->getReason());
                }

                return $this->files->serve($package->getBlobs()[0], 'application/octet-stream');
            }
        }

        return self::notFound();
    }

    /** @return array<string, array<string, mixed>> documents de version triés, adresses réécrites vers le miroir */
    private function versions(Request $request, string $name): array
    {
        $base = $this->files->baseUrl($request);
        $versions = [];
        /** @var Package $package */
        foreach ($this->packages->findBy(['ecosystem' => Ecosystem::Npm, 'name' => $name]) as $package) {
            // Versions en quarantaine : absentes du document, npm choisit une autre version
            if ($this->quarantine->forPackage($package)) {
                continue;
            }
            $document = $package->getMetadata();
            $tarball = $document['dist']['tarballName'] ?? sprintf('%s-%s.tgz', basename($name), $package->getVersion());
            unset($document['dist']['tarballName']);
            $document['dist']['tarball'] = sprintf('%s/npm/%s/-/%s', $base, $name, $tarball);
            $document['_mirroredAt'] = $package->getCreatedAt()->format('Y-m-d\TH:i:s.v\Z');
            $versions[$package->getVersion()] = $document;
        }
        uksort($versions, static fn ($a, $b) => Semver::compare((string) $a, (string) $b));

        return $versions;
    }

    private static function quarantined(string $name, string $version, string $reason): JsonResponse
    {
        return new JsonResponse(['error' => sprintf('%s@%s est en quarantaine (liste noire)%s', $name, $version, '' !== $reason ? ' : '.$reason : '')], 403);
    }

    private static function notFound(): JsonResponse
    {
        return new JsonResponse(['error' => 'Not found (paquet absent du miroir hors ligne)'], 404);
    }
}
