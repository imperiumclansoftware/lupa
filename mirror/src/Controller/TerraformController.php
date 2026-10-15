<?php

namespace App\Controller;

use App\Entity\Package;
use App\Quarantine\Quarantine;
use App\Registry\Files;
use App\Repository\PackageRepository;
use Sas\Bundle\BlobStore;
use Sas\Bundle\Ecosystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Terraform, en lecture seule :
 *  - providers : protocole « network mirror » (/terraform/providers/, HTTPS exigé par Terraform) et
 *    archive « filesystem mirror » (/terraform/providers.tar) pour un miroir en HTTP ;
 *  - modules : registre (découverte /.well-known/terraform.json) et archives directes ;
 *  - binaire terraform : /terraform/releases/.
 */
final class TerraformController
{
    public function __construct(
        private readonly PackageRepository $packages,
        private readonly Files $files,
        private readonly Quarantine $quarantine,
        private readonly BlobStore $store,
    ) {
    }

    #[Route('/.well-known/terraform.json', methods: ['GET', 'HEAD'])]
    public function discovery(): JsonResponse
    {
        return new JsonResponse(['modules.v1' => '/terraform/modules/v1/']);
    }

    #[Route('/terraform/providers/{host}/{namespace}/{type}/index.json', methods: ['GET', 'HEAD'], requirements: ['host' => '[\w.-]+', 'namespace' => '[\w-]+', 'type' => '[\w-]+'])]
    public function providerIndex(string $host, string $namespace, string $type): JsonResponse
    {
        $versions = [];
        foreach ($this->providers($host, $namespace, $type) as $package) {
            $versions[$package->getVersion()] = new \stdClass();
        }

        return [] === $versions ? self::notFound() : new JsonResponse(['versions' => $versions]);
    }

    #[Route('/terraform/providers/{host}/{namespace}/{type}/{version}.json', methods: ['GET', 'HEAD'], requirements: ['host' => '[\w.-]+', 'namespace' => '[\w-]+', 'type' => '[\w-]+', 'version' => '[^/]+'], priority: -1)]
    public function providerVersion(string $host, string $namespace, string $type, string $version): JsonResponse
    {
        $archives = [];
        foreach ($this->providers($host, $namespace, $type) as $package) {
            if ($package->getVersion() !== $version) {
                continue;
            }
            $metadata = $package->getMetadata();
            $archives[$metadata['os'].'_'.$metadata['arch']] = [
                'url' => (string) $package->getFilename(),
                // « zh: » : sha256 de l'archive, accepté dans .terraform.lock.hcl
                'hashes' => ['zh:'.$package->getBlobs()[0]],
            ];
        }

        return [] === $archives ? self::notFound() : new JsonResponse(['archives' => $archives]);
    }

    #[Route('/terraform/providers/{host}/{namespace}/{type}/{filename}', methods: ['GET', 'HEAD'], requirements: ['host' => '[\w.-]+', 'namespace' => '[\w-]+', 'type' => '[\w-]+', 'filename' => 'terraform-provider-[^/]+\.zip'])]
    public function providerArchive(string $host, string $namespace, string $type, string $filename): Response
    {
        foreach ($this->providers($host, $namespace, $type, true) as $package) {
            if ($package->getFilename() === $filename) {
                return $this->serve($package, 'application/zip');
            }
        }

        return self::notFound();
    }

    /**
     * Tous les providers (ou une plateforme) dans l'arborescence d'un « filesystem_mirror » :
     *   registry.terraform.io/hashicorp/aws/terraform-provider-aws_5.0.0_linux_amd64.zip
     */
    #[Route('/terraform/providers.tar', methods: ['GET'])]
    public function providersTar(Request $request): Response
    {
        $platform = $request->query->getString('platform');
        $entries = [];
        foreach ($this->packages->findBy(['ecosystem' => Ecosystem::Terraform]) as $package) {
            $m = $package->getMetadata();
            if ('provider' !== ($m['kind'] ?? null) || $this->quarantine->forPackage($package)
                || ('' !== $platform && $platform !== ($m['os'] ?? '').'_'.($m['arch'] ?? ''))) {
                continue;
            }
            $entries[sprintf('%s/%s/%s/%s', $m['host'], $m['namespace'], $m['type'], $package->getFilename())] = $this->store->path($package->getBlobs()[0]);
        }
        if ([] === $entries) {
            return self::notFound();
        }
        ksort($entries);
        $response = new StreamedResponse(static function () use ($entries): void {
            set_time_limit(0);
            $out = fopen('php://output', 'w');
            foreach ($entries as $name => $path) {
                $size = (int) filesize($path);
                fwrite($out, self::tarHeader($name, $size));
                $in = fopen($path, 'r');
                stream_copy_to_stream($in, $out);
                fclose($in);
                fwrite($out, str_repeat("\0", (512 - $size % 512) % 512));
            }
            fwrite($out, str_repeat("\0", 1024));
            fclose($out);
        });
        $response->headers->set('Content-Type', 'application/x-tar');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="terraform-providers%s.tar"', '' !== $platform ? '-'.$platform : ''));

        return $response;
    }

    #[Route('/terraform/modules/v1/{namespace}/{name}/{provider}/versions', methods: ['GET', 'HEAD'], requirements: ['namespace' => '[\w-]+', 'name' => '[\w-]+', 'provider' => '[\w-]+'])]
    public function moduleVersions(string $namespace, string $name, string $provider): JsonResponse
    {
        $versions = array_map(static fn (Package $p) => ['version' => $p->getVersion()], $this->modules($namespace, $name, $provider));

        return [] === $versions ? self::notFound() : new JsonResponse(['modules' => [['source' => "$namespace/$name/$provider", 'versions' => $versions]]]);
    }

    #[Route('/terraform/modules/v1/{namespace}/{name}/{provider}/{version}/download', methods: ['GET', 'HEAD'], requirements: ['namespace' => '[\w-]+', 'name' => '[\w-]+', 'provider' => '[\w-]+', 'version' => '[^/]+'])]
    public function moduleDownload(Request $request, string $namespace, string $name, string $provider, string $version): Response
    {
        foreach ($this->modules($namespace, $name, $provider) as $package) {
            if ($package->getVersion() === $version) {
                return new Response('', 204, ['X-Terraform-Get' => sprintf('%s/terraform/modules/%s/%s/%s/%s.tar.gz', $this->files->baseUrl($request), $namespace, $name, $provider, $version)]);
            }
        }

        return self::notFound();
    }

    #[Route('/terraform/modules/{namespace}/{name}/{provider}/{version}.tar.gz', methods: ['GET', 'HEAD'], requirements: ['namespace' => '[\w-]+', 'name' => '[\w-]+', 'provider' => '[\w-]+', 'version' => '[^/]+'])]
    public function moduleArchive(string $namespace, string $name, string $provider, string $version): Response
    {
        foreach ($this->modules($namespace, $name, $provider, true) as $package) {
            if ($package->getVersion() === $version) {
                return $this->serve($package, 'application/gzip');
            }
        }

        return self::notFound();
    }

    #[Route('/terraform/releases/{filename}', methods: ['GET', 'HEAD'], requirements: ['filename' => 'terraform_[^/]+\.zip'])]
    public function release(string $filename): Response
    {
        $package = $this->packages->findOneBy(['ecosystem' => Ecosystem::Terraform, 'name' => 'terraform', 'filename' => $filename]);

        return $package ? $this->serve($package, 'application/zip') : self::notFound();
    }

    /** @return list<Package> */
    private function providers(string $host, string $namespace, string $type, bool $withQuarantined = false): array
    {
        return array_values(array_filter(
            $this->packages->findBy(['ecosystem' => Ecosystem::Terraform, 'name' => strtolower("$host/$namespace/$type")]),
            fn (Package $p) => 'provider' === ($p->getMetadata()['kind'] ?? null) && ($withQuarantined || !$this->quarantine->forPackage($p)),
        ));
    }

    /** @return list<Package> */
    private function modules(string $namespace, string $name, string $provider, bool $withQuarantined = false): array
    {
        $modules = array_values(array_filter(
            $this->packages->findBy(['ecosystem' => Ecosystem::Terraform, 'name' => strtolower("$namespace/$name/$provider")]),
            fn (Package $p) => 'module' === ($p->getMetadata()['kind'] ?? null) && ($withQuarantined || !$this->quarantine->forPackage($p)),
        ));
        usort($modules, static fn (Package $a, Package $b) => version_compare($b->getVersion(), $a->getVersion()));

        return $modules;
    }

    private function serve(Package $package, string $type): Response
    {
        if ($entry = $this->quarantine->forPackage($package)) {
            return new Response(sprintf("%s %s est en quarantaine (liste noire)%s\n", $entry->getName(), $entry->getVersion(), '' !== $entry->getReason() ? ' : '.$entry->getReason() : ''), 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return $this->files->serve($package->getBlobs()[0], $type);
    }

    /** En-tête ustar (noms de plus de 100 caractères : champ « prefix »). */
    private static function tarHeader(string $name, int $size): string
    {
        $prefix = '';
        if (\strlen($name) > 100 && false !== $cut = strrpos(substr($name, 0, 155), '/')) {
            [$prefix, $name] = [substr($name, 0, $cut), substr($name, $cut + 1)];
        }
        $header = pack('a100a8a8a8a12a12', $name, '0000644', '0000000', '0000000', sprintf('%011o', $size), sprintf('%011o', time()))
            .'        '.'0'.str_repeat("\0", 100).'ustar'."\0".'00'.pack('a32a32a8a8a155a12', 'root', 'root', '', '', $prefix, '');
        $checksum = array_sum(array_map('ord', str_split($header)));

        return substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);
    }

    private static function notFound(): JsonResponse
    {
        return new JsonResponse(['errors' => ['Absent du miroir hors ligne.']], 404);
    }
}
