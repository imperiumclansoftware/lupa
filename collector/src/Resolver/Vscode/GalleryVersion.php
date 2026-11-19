<?php

namespace App\Resolver\Vscode;

/** Une version publiée d'une extension, pour une plateforme (« universal » = toutes). */
final readonly class GalleryVersion
{
    /**
     * @param list<string>          $dependencies  extensionDependencies (éditeur.nom)
     * @param list<string>          $extensionPack extensions d'un pack (éditeur.nom)
     * @param array<string, string> $properties    propriétés de la galerie (moteur, préversion, liens…)
     * @param array<string, string> $files         type de fichier de la galerie => adresse
     */
    public function __construct(
        public string $version,
        public string $targetPlatform,
        public bool $preRelease,
        public string $engine,
        public array $dependencies,
        public array $extensionPack,
        public ?string $lastUpdated,
        public array $properties,
        public array $files,
    ) {
    }

    /** Lecture d'une version telle que renvoyée par « extensionquery » ; null si elle est inexploitable. */
    public static function fromGallery(mixed $raw): ?self
    {
        if (!\is_array($raw) || !\is_string($raw['version'] ?? null)) {
            return null;
        }
        $properties = [];
        foreach (\is_array($raw['properties'] ?? null) ? $raw['properties'] : [] as $property) {
            if (\is_array($property) && \is_string($property['key'] ?? null)) {
                $properties[$property['key']] = \is_scalar($property['value'] ?? null) ? (string) $property['value'] : '';
            }
        }
        $files = [];
        foreach (\is_array($raw['files'] ?? null) ? $raw['files'] : [] as $file) {
            if (\is_array($file) && \is_string($file['assetType'] ?? null) && \is_string($file['source'] ?? null)) {
                $files[$file['assetType']] = $file['source'];
            }
        }
        $ids = static fn (string $key): array => array_values(array_filter(array_map(trim(...), explode(',', $properties[$key] ?? '')), static fn (string $id) => '' !== $id));
        $platform = \is_string($raw['targetPlatform'] ?? null) && '' !== $raw['targetPlatform'] ? $raw['targetPlatform'] : 'universal';

        return new self(
            $raw['version'],
            $platform,
            'true' === ($properties['Microsoft.VisualStudio.Code.PreRelease'] ?? ''),
            $properties['Microsoft.VisualStudio.Code.Engine'] ?? '*',
            $ids('Microsoft.VisualStudio.Code.ExtensionDependencies'),
            $ids('Microsoft.VisualStudio.Code.ExtensionPack'),
            \is_string($raw['lastUpdated'] ?? null) ? $raw['lastUpdated'] : null,
            $properties,
            $files,
        );
    }

    public function file(string $assetType): ?string
    {
        return $this->files[$assetType] ?? null;
    }
}
