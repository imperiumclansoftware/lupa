<?php

namespace App\Registry;

use App\Entity\Package;
use App\Quarantine\Quarantine;
use App\Repository\PackageRepository;
use Sas\Bundle\BlobStore;
use Sas\Bundle\Ecosystem;

/**
 * Catalogue des extensions VS Code du miroir, au format de la galerie de VS Code (celui d'Open VSX et du
 * Marketplace) : c'est ce qu'attendent VS Code, VSCodium et code-server dans « extensionsGallery ».
 * Les versions en quarantaine (liste noire de Rémus) ne sont jamais annoncées.
 */
final class VscodeGallery
{
    public const string VSIX = 'Microsoft.VisualStudio.Services.VSIXPackage';
    public const string MANIFEST = 'Microsoft.VisualStudio.Code.Manifest';

    /** Fichiers servis depuis le VSIX quand Rémus ne les a pas collectés à part */
    private const array IN_VSIX = [
        self::MANIFEST => 'extension/package.json',
        'Microsoft.VisualStudio.Services.VsixManifest' => 'extension.vsixmanifest',
    ];

    /** @var array<string, list<Package>>|null extensions servies, par identifiant */
    private ?array $catalog = null;

    public function __construct(
        private readonly PackageRepository $packages,
        private readonly Quarantine $quarantine,
        private readonly BlobStore $store,
    ) {
    }

    /**
     * Constructions servies, par extension (identifiant en minuscules), de la plus récente à la plus ancienne.
     *
     * @return array<string, list<Package>>
     */
    public function catalog(): array
    {
        if (null === $this->catalog) {
            $catalog = [];
            foreach ($this->packages->findBy(['ecosystem' => Ecosystem::Vscode]) as $package) {
                if (null === $this->quarantine->forPackage($package)) {
                    $catalog[$package->getName()][] = $package;
                }
            }
            foreach ($catalog as &$builds) {
                usort($builds, static fn (Package $a, Package $b) => version_compare($b->getVersion(), $a->getVersion()) ?: strcmp(self::platform($a), self::platform($b)));
            }
            ksort($catalog);
            $this->catalog = $catalog;
        }

        return $this->catalog;
    }

    /**
     * Extension au format de la galerie ; versions : toutes, ou la dernière (toutes ses plateformes).
     *
     * @param list<Package> $builds
     *
     * @return array<string, mixed>
     */
    public function extension(array $builds, string $baseUrl, bool $latestOnly): array
    {
        $first = $builds[0]->getMetadata();
        if ($latestOnly) {
            $latest = $builds[0]->getVersion();
            $builds = array_values(array_filter($builds, static fn (Package $p) => $p->getVersion() === $latest));
        }
        [$publisher, $name] = self::split($builds[0]);

        return [
            'extensionId' => self::text($first, 'extensionId') ?: self::uuid('extension:'.$builds[0]->getName()),
            'extensionName' => $name,
            'displayName' => self::text($first, 'displayName') ?: $name,
            'shortDescription' => self::text($first, 'description'),
            'publisher' => [
                'publisherId' => self::text($first, 'publisherId') ?: self::uuid('publisher:'.$publisher),
                'publisherName' => $publisher,
                'displayName' => self::text($first, 'publisherDisplayName') ?: $publisher,
                'domain' => null,
                'isDomainVerified' => false,
            ],
            'versions' => array_map(fn (Package $p) => $this->version($p, $baseUrl), $builds),
            'statistics' => [['statisticName' => 'install', 'value' => 0]],
            'tags' => self::strings($first['tags'] ?? null),
            'categories' => self::strings($first['categories'] ?? null),
            'releaseDate' => self::text($first, 'releaseDate') ?: $builds[0]->getCreatedAt()->format(\DATE_ATOM),
            'publishedDate' => self::text($first, 'releaseDate') ?: $builds[0]->getCreatedAt()->format(\DATE_ATOM),
            'lastUpdated' => self::text($first, 'lastUpdated') ?: $builds[0]->getUpdatedAt()->format(\DATE_ATOM),
            'flags' => 'validated, public',
        ];
    }

    /** Construction servie pour une version et une plateforme (l'universelle à défaut). */
    public function find(string $id, string $version, ?string $platform): ?Package
    {
        $fallback = null;
        foreach ($this->catalog()[strtolower($id)] ?? [] as $package) {
            if ($package->getVersion() !== $version) {
                continue;
            }
            if (self::platform($package) === ($platform ?: 'universal')) {
                return $package;
            }
            if ('universal' === self::platform($package) || null === $platform) {
                $fallback ??= $package;
            }
        }

        return $fallback;
    }

    /**
     * Fichier d'une construction : empreinte collectée à part, ou contenu lu dans le VSIX.
     *
     * @return array{sha256: string}|array{content: string}|null
     */
    public function asset(Package $package, string $assetType): ?array
    {
        if (self::VSIX === $assetType) {
            return ['sha256' => $package->getBlobs()[0]];
        }
        $assets = $package->getMetadata()['assets'] ?? [];
        $sha256 = \is_array($assets) ? ($assets[$assetType] ?? null) : null;
        if (\is_string($sha256) && $this->store->has($sha256)) {
            return ['sha256' => $sha256];
        }
        if (isset(self::IN_VSIX[$assetType]) && null !== $content = $this->fromVsix($package, self::IN_VSIX[$assetType])) {
            return ['content' => $content];
        }

        return null;
    }

    /** Fichier du dossier « extension/ » d'un VSIX (resourceUrlTemplate : images du README, extensions web). */
    public function fromVsix(Package $package, string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ('' === $path || str_contains('/'.$path.'/', '/../')) {
            return null;
        }

        return $this->store->withFile($package->getBlobs()[0], static function (string $file) use ($path): ?string {
            $zip = new \ZipArchive();
            if (true !== $zip->open($file, \ZipArchive::RDONLY)) {
                return null;
            }
            try {
                $content = $zip->getFromName($path);

                return false === $content ? null : $content;
            } finally {
                $zip->close();
            }
        });
    }

    public static function platform(Package $package): string
    {
        $platform = $package->getMetadata()['targetPlatform'] ?? 'universal';

        return \is_string($platform) && '' !== $platform ? $platform : 'universal';
    }

    /** @return array{string, string} éditeur, nom (casse d'origine si connue) */
    public static function split(Package $package): array
    {
        $metadata = $package->getMetadata();
        $publisher = self::text($metadata, 'publisher');
        $name = self::text($metadata, 'name');
        if ('' === $publisher || '' === $name) {
            [$publisher, $name] = array_pad(explode('.', $package->getName(), 2), 2, '');
        }

        return [$publisher, $name];
    }

    /** @return array<string, mixed> */
    private function version(Package $package, string $baseUrl): array
    {
        [$publisher, $name] = self::split($package);
        $platform = self::platform($package);
        $assetUri = sprintf('%s/vscode/asset/%s/%s/%s', $baseUrl, rawurlencode($publisher), rawurlencode($name), rawurlencode($package->getVersion()));
        $query = 'universal' === $platform ? '' : '?targetPlatform='.rawurlencode($platform);
        $assets = $package->getMetadata()['assets'] ?? [];
        $types = [self::VSIX, self::MANIFEST, ...array_keys(\is_array($assets) ? $assets : [])];
        $properties = $package->getMetadata()['properties'] ?? [];

        return [
            'version' => $package->getVersion(),
            'targetPlatform' => $platform,
            'lastUpdated' => self::text($package->getMetadata(), 'lastUpdated') ?: $package->getUpdatedAt()->format(\DATE_ATOM),
            'assetUri' => $assetUri,
            'fallbackAssetUri' => $assetUri,
            'files' => array_map(static fn (string $type) => ['assetType' => $type, 'source' => $assetUri.'/'.$type.$query], array_values(array_unique(array_map(strval(...), $types)))),
            'properties' => array_map(
                static fn (string|int $key, mixed $value) => ['key' => (string) $key, 'value' => \is_scalar($value) ? (string) $value : ''],
                array_keys(\is_array($properties) ? $properties : []),
                array_values(\is_array($properties) ? $properties : []),
            ),
        ];
    }

    /** @param array<mixed> $data */
    private static function text(array $data, string $key): string
    {
        return \is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
    }

    /** @return list<string> */
    private static function strings(mixed $list): array
    {
        return array_values(array_filter(\is_array($list) ? $list : [], \is_string(...)));
    }

    /** Identifiant stable (UUID v5) pour une extension dont la galerie d'origine n'en fournissait pas */
    private static function uuid(string $name): string
    {
        $hash = sha1('lupa-vscode:'.$name);

        return sprintf('%s-%s-5%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3), dechex(0x8 | (hexdec($hash[16]) & 0x3)).substr($hash, 17, 3), substr($hash, 20, 12));
    }
}
