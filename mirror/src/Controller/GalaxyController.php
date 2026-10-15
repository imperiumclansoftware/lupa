<?php

namespace App\Controller;

use App\Entity\Package;
use App\Quarantine\Quarantine;
use App\Registry\Files;
use App\Repository\PackageRepository;
use Sas\Bundle\Ecosystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serveur Ansible Galaxy (API v3, lecture seule) pour ansible-galaxy :
 *   ansible-galaxy collection install community.general -s http://miroir/galaxy/
 * Rôles : archives servies sous /galaxy/roles/, à installer par leur adresse.
 */
final class GalaxyController
{
    public function __construct(
        private readonly PackageRepository $packages,
        private readonly Files $files,
        private readonly Quarantine $quarantine,
    ) {
    }

    #[Route('/galaxy/api/', methods: ['GET', 'HEAD'])]
    public function root(): JsonResponse
    {
        return new JsonResponse(['available_versions' => ['v3' => 'v3/'], 'current_version' => 'v3', 'description' => 'Romulus (miroir hors ligne Lupa)']);
    }

    #[Route('/galaxy/api/v3/collections/{namespace}/{name}/', methods: ['GET', 'HEAD'], requirements: ['namespace' => '\w+', 'name' => '[\w-]+'])]
    public function collection(Request $request, string $namespace, string $name): JsonResponse
    {
        $versions = $this->versions($namespace, $name);
        if ([] === $versions) {
            return self::notFound();
        }
        $base = $this->base($request, $namespace, $name);
        $highest = array_key_first($versions);

        return new JsonResponse([
            'namespace' => $namespace,
            'name' => $name,
            'href' => $base,
            'versions_url' => $base.'versions/',
            'highest_version' => ['version' => $highest, 'href' => $base.'versions/'.$highest.'/'],
        ]);
    }

    #[Route('/galaxy/api/v3/collections/{namespace}/{name}/versions/', methods: ['GET', 'HEAD'], requirements: ['namespace' => '\w+', 'name' => '[\w-]+'])]
    public function versionList(Request $request, string $namespace, string $name): JsonResponse
    {
        $versions = $this->versions($namespace, $name);
        if ([] === $versions) {
            return self::notFound();
        }
        $base = $this->base($request, $namespace, $name);
        $first = $base.'versions/?limit='.\count($versions).'&offset=0';

        return new JsonResponse([
            'meta' => ['count' => \count($versions)],
            'links' => ['first' => $first, 'previous' => null, 'next' => null, 'last' => $first],
            'data' => array_map(static fn (string $v) => ['version' => $v, 'href' => $base.'versions/'.$v.'/'], array_keys($versions)),
        ]);
    }

    #[Route('/galaxy/api/v3/collections/{namespace}/{name}/versions/{version}/', methods: ['GET', 'HEAD'], requirements: ['namespace' => '\w+', 'name' => '[\w-]+', 'version' => '[^/]+'])]
    public function version(Request $request, string $namespace, string $name, string $version): JsonResponse
    {
        $package = $this->versions($namespace, $name, true)[$version] ?? null;
        if (!$package) {
            return self::notFound();
        }
        if ($entry = $this->quarantine->forPackage($package)) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'quarantine', 'detail' => sprintf('%s.%s %s est en quarantaine (liste noire)%s', $namespace, $name, $version, '' !== $entry->getReason() ? ' : '.$entry->getReason() : '')]]], 403);
        }
        $metadata = $package->getMetadata();
        $filename = (string) ($metadata['filename'] ?? sprintf('%s-%s-%s.tar.gz', $namespace, $name, $version));
        $base = $this->base($request, $namespace, $name);

        return new JsonResponse([
            'href' => $base.'versions/'.$version.'/',
            'namespace' => ['name' => $namespace],
            'collection' => ['name' => $name, 'href' => $base],
            'name' => $name,
            'version' => $version,
            'download_url' => sprintf('%s/galaxy/download/%s', $this->files->baseUrl($request), rawurlencode($filename)),
            'artifact' => ['filename' => $filename, 'sha256' => $package->getBlobs()[0], 'size' => $package->getSize()],
            'metadata' => ['dependencies' => (object) ($metadata['dependencies'] ?? [])],
            'requires_ansible' => $metadata['requiresAnsible'] ?? null,
            'signatures' => [],
        ]);
    }

    #[Route('/galaxy/download/{filename}', methods: ['GET', 'HEAD'], requirements: ['filename' => '[\w.-]+\.tar\.gz'])]
    public function download(string $filename): Response
    {
        return $this->serve(static fn (Package $p) => 'role' !== ($p->getMetadata()['type'] ?? null) && ($p->getMetadata()['filename'] ?? null) === $filename);
    }

    #[Route('/galaxy/roles/{filename}', methods: ['GET', 'HEAD'], requirements: ['filename' => '[\w.-]+\.tar\.gz'])]
    public function role(string $filename): Response
    {
        return $this->serve(static fn (Package $p) => $p->getFilename() === 'roles/'.$filename);
    }

    /** @param callable(Package): bool $match */
    private function serve(callable $match): Response
    {
        foreach ($this->packages->findBy(['ecosystem' => Ecosystem::Galaxy]) as $package) {
            if ($match($package)) {
                if ($entry = $this->quarantine->forPackage($package)) {
                    return new Response(sprintf("%s %s est en quarantaine (liste noire)\n", $entry->getName(), $entry->getVersion()), 403, ['Content-Type' => 'text/plain; charset=utf-8']);
                }

                return $this->files->serve($package->getBlobs()[0], 'application/gzip');
            }
        }

        return new Response("Absent du miroir hors ligne.\n", 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * Versions servies d'une collection (hors quarantaine), de la plus récente à la plus ancienne.
     *
     * @return array<string, Package>
     */
    private function versions(string $namespace, string $name, bool $withQuarantined = false): array
    {
        $versions = [];
        foreach ($this->packages->findBy(['ecosystem' => Ecosystem::Galaxy, 'name' => strtolower($namespace.'.'.$name)]) as $package) {
            if ('role' === ($package->getMetadata()['type'] ?? null) || (!$withQuarantined && $this->quarantine->forPackage($package))) {
                continue;
            }
            $versions[$package->getVersion()] = $package;
        }
        uksort($versions, static fn (string $a, string $b) => version_compare($b, $a));

        return $versions;
    }

    private function base(Request $request, string $namespace, string $name): string
    {
        return sprintf('%s/galaxy/api/v3/collections/%s/%s/', $this->files->baseUrl($request), $namespace, $name);
    }

    private static function notFound(): JsonResponse
    {
        return new JsonResponse(['errors' => [['status' => '404', 'code' => 'not_found', 'detail' => 'Absent du miroir hors ligne.']]], 404);
    }
}
