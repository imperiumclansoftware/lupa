<?php

namespace App\Resolver;

use App\Blacklist\Blacklist;
use App\Entity\Artifact;
use App\Entity\PackageList;
use App\Job\JobContext;
use App\Resolver\Docker\ImageReference;
use App\Search\Glob;
use App\Resolver\Docker\RegistryClient;
use Sas\Bundle\BlobStore;
use Sas\Bundle\Ecosystem;

/**
 * Docker : pour chaque image, l'index multi-plateformes est conservé tel quel (son empreinte ne change pas)
 * et seules les plateformes demandées sont téléchargées. Les couches communes entre images ne sont
 * stockées et transportées qu'une fois.
 */
final readonly class DockerResolver implements Resolver
{
    public const array PLATFORMS = [
        'linux/amd64' => 'Linux x86_64 (amd64)',
        'linux/arm64' => 'Linux ARM64',
        'linux/arm/v7' => 'Linux ARM v7',
        'linux/386' => 'Linux x86 32 bits',
        'linux/ppc64le' => 'Linux POWER',
        'linux/s390x' => 'Linux IBM Z',
        'windows/amd64' => 'Windows amd64',
    ];

    /** Version du contenu collecté (2 : avec les attestations) ; une version plus ancienne est recollectée. */
    private const int FORMAT = 2;

    private const array INDEX_TYPES = [
        'application/vnd.oci.image.index.v1+json',
        'application/vnd.docker.distribution.manifest.list.v2+json',
    ];

    public function __construct(
        private RegistryClient $registry,
        private ArtifactCatalog $catalog,
        private BlobStore $store,
        private Blacklist $blacklist,
    ) {
    }

    public function ecosystem(): Ecosystem
    {
        return Ecosystem::Docker;
    }

    /** @return list<string> */
    public static function platforms(PackageList $list): array
    {
        $platforms = $list->getOptions()['platforms'] ?? [];

        return \is_array($platforms) && [] !== $platforms ? array_values($platforms) : ['linux/amd64'];
    }

    public function validate(PackageList $list): array
    {
        $errors = [];
        foreach ($list->getSpecLines() as $line) {
            if (Glob::isPattern($line)) {
                // Motif (node:24-*alpine, bitnami/*-exporter) : développé au moment de la mise à jour
                if (!preg_match('#^[a-z0-9*?][a-z0-9._/*?-]*(:[\w.*?-]+)?$#i', $line)) {
                    $errors[] = sprintf('« %s » : motif d\'image invalide (ex. node:24-*alpine, bitnami/*-exporter).', $line);
                }
                continue;
            }
            try {
                ImageReference::parse($line);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        foreach (self::platforms($list) as $platform) {
            if (!isset(self::PLATFORMS[$platform])) {
                $errors[] = sprintf('Plateforme inconnue : %s.', $platform);
            }
        }
        if ([] === $list->getSpecLines()) {
            $errors[] = 'La liste est vide : saisissez des images (ex. nginx:1.27, ghcr.io/owner/app:1.0).';
        }

        return $errors;
    }

    public function resolve(PackageList $list, JobContext $context): array
    {
        $platforms = self::platforms($list);
        $lines = $list->getSpecLines();
        $artifacts = [];
        foreach ($lines as $i => $line) {
            $image = ImageReference::parse($line);
            if ($this->blacklist->blocks(Ecosystem::Docker, $image->mirrorName(), $image->reference, $context)) {
                continue;
            }
            $context->report(sprintf('Image %s', $image), (int) ($i * 100 / \count($lines)));
            $artifacts[] = $this->pull($image, $platforms, $context);
            $this->catalog->flush();
        }

        return $artifacts;
    }

    /** @param list<string> $platforms */
    private function pull(ImageReference $image, array $platforms, JobContext $context): Artifact
    {
        $top = $this->registry->manifest($image, $image->reference);

        $existing = $this->catalog->complete(Ecosystem::Docker, $image->mirrorName(), $image->reference);
        if ($existing && ($existing->getMetadata()['digest'] ?? null) === $top['digest']
            && ($existing->getMetadata()['platforms'] ?? []) === $platforms
            && self::FORMAT === ($existing->getMetadata()['format'] ?? 1)) {
            $context->log(sprintf('%s : inchangée (%s).', $image, substr($top['digest'], 7, 12)));

            return $existing;
        }

        $blobs = [['sha256' => substr($top['digest'], 7), 'size' => $top['size']]];
        $manifests = [];

        if (\in_array($top['mediaType'], self::INDEX_TYPES, true)) {
            $entries = $top['document']['manifests'] ?? [];
            foreach ($entries as $entry) {
                $platform = self::platformOf($entry['platform'] ?? []);
                if (null === $platform || !self::matches($platform, $platforms)) {
                    continue;
                }
                $child = $this->registry->manifest($image, $entry['digest']);
                $blobs[] = ['sha256' => substr($child['digest'], 7), 'size' => $child['size']];
                $manifests[] = ['digest' => $child['digest'], 'mediaType' => $child['mediaType'], 'platform' => $platform];
                array_push($blobs, ...$this->layers($image, $child['document'], $platform, $context));
            }
            if ([] === $manifests) {
                $available = array_filter(array_map(fn ($e) => self::platformOf($e['platform'] ?? []), $entries));
                throw new \RuntimeException(sprintf('%s n\'existe pour aucune des plateformes demandées. Disponibles : %s.', $image, implode(', ', array_unique($available))));
            }

            // Attestations (provenance, SBOM) des plateformes retenues : quelques Ko, que Docker lit aussi au pull
            $selected = array_flip(array_column($manifests, 'digest'));
            foreach ($entries as $entry) {
                $annotations = $entry['annotations'] ?? [];
                if ('attestation-manifest' === ($annotations['vnd.docker.reference.type'] ?? null)
                    && isset($selected[$annotations['vnd.docker.reference.digest'] ?? ''])) {
                    $child = $this->registry->manifest($image, $entry['digest']);
                    $blobs[] = ['sha256' => substr($child['digest'], 7), 'size' => $child['size']];
                    $manifests[] = ['digest' => $child['digest'], 'mediaType' => $child['mediaType'], 'platform' => 'attestation'];
                    array_push($blobs, ...$this->layers($image, $child['document'], 'attestation', $context));
                }
            }
        } else {
            $config = $this->registry->blob($image, $top['document']['config']['digest']);
            $configDoc = json_decode($this->store->read($config['sha256']), true) ?: [];
            $platform = self::platformOf($configDoc) ?? 'inconnue';
            if (!self::matches($platform, $platforms)) {
                $context->log(sprintf('ATTENTION %s n\'est publiée que pour %s : téléchargée quand même.', $image, $platform));
            }
            $manifests[] = ['digest' => $top['digest'], 'mediaType' => $top['mediaType'], 'platform' => $platform];
            array_push($blobs, ...$this->layers($image, $top['document'], $platform, $context));
        }

        $size = array_sum(array_column($blobs, 'size'));
        $platformsFound = array_values(array_unique(array_diff(array_column($manifests, 'platform'), ['attestation'])));
        $context->log(sprintf('%s : %s, %s.', $image, implode(', ', $platformsFound), self::humanSize($size)));

        return $this->catalog->record(Ecosystem::Docker, $image->mirrorName(), $image->reference, null, $blobs, [
            'registry' => $image->registry,
            'repository' => $image->repository,
            'digest' => $top['digest'],
            'mediaType' => $top['mediaType'],
            'manifests' => $manifests,
            'platforms' => $platforms,
            'format' => self::FORMAT,
        ]);
    }

    /**
     * Configuration et couches d'un manifeste d'image.
     *
     * @param array<string, mixed> $manifest
     *
     * @return list<array{sha256: string, size: int}>
     */
    private function layers(ImageReference $image, array $manifest, string $platform, JobContext $context): array
    {
        $blobs = [$this->registry->blob($image, $manifest['config']['digest'])];
        $layers = $manifest['layers'] ?? [];
        foreach ($layers as $i => $layer) {
            // Couches « étrangères » (images Windows) : hébergées hors du registre, non redistribuables
            if (!empty($layer['urls']) || str_contains($layer['mediaType'] ?? '', 'foreign') || str_contains($layer['mediaType'] ?? '', 'nondistributable')) {
                $context->log(sprintf('Couche non redistribuable ignorée dans %s (%s).', $image, $platform));
                continue;
            }
            $context->report(sprintf('%s (%s) : couche %d / %d, %s', $image, $platform, $i + 1, \count($layers), self::humanSize((int) ($layer['size'] ?? 0))));
            $blobs[] = $this->registry->blob($image, $layer['digest']);
        }

        return $blobs;
    }

    /** @param array<string, mixed> $platform */
    private static function platformOf(array $platform): ?string
    {
        $os = $platform['os'] ?? null;
        $arch = $platform['architecture'] ?? null;
        if (!$os || !$arch || 'unknown' === $os) {
            return null;
        }

        return $os.'/'.$arch.(!empty($platform['variant']) ? '/'.$platform['variant'] : '');
    }

    /** @param list<string> $wanted linux/arm64 accepte linux/arm64/v8 */
    private static function matches(string $platform, array $wanted): bool
    {
        foreach ($wanted as $w) {
            if ($platform === $w || str_starts_with($platform, $w.'/')) {
                return true;
            }
        }

        return false;
    }

    private static function humanSize(int $bytes): string
    {
        return $bytes >= 1 << 30 ? sprintf('%.1f Go', $bytes / (1 << 30)) : sprintf('%.1f Mo', $bytes / (1 << 20));
    }
}
