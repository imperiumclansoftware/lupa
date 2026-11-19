<?php

namespace App\Resolver\Vscode;

/** Une extension de la galerie et ses versions, de la plus récente à la plus ancienne. */
final readonly class GalleryExtension
{
    /**
     * @param list<string>         $categories
     * @param list<string>         $tags
     * @param list<GalleryVersion> $versions
     */
    public function __construct(
        public string $id,
        public string $extensionId,
        public string $publisherId,
        public string $publisher,
        public string $publisherDisplayName,
        public string $name,
        public string $displayName,
        public string $description,
        public array $categories,
        public array $tags,
        public ?string $releaseDate,
        public array $versions,
    ) {
    }

    public static function fromGallery(mixed $raw): ?self
    {
        if (!\is_array($raw)) {
            return null;
        }
        $publisher = \is_array($raw['publisher'] ?? null) ? $raw['publisher'] : [];
        $text = static fn (array $data, string $key, string $default = ''): string => \is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : $default;
        $strings = static fn (mixed $list): array => array_values(array_filter(\is_array($list) ? $list : [], \is_string(...)));
        $name = $text($raw, 'extensionName');
        $publisherName = $text($publisher, 'publisherName');
        if ('' === $name || '' === $publisherName) {
            return null;
        }

        return new self(
            $publisherName.'.'.$name,
            $text($raw, 'extensionId'),
            $text($publisher, 'publisherId'),
            $publisherName,
            $text($publisher, 'displayName', $publisherName) ?: $publisherName,
            $name,
            $text($raw, 'displayName', $name) ?: $name,
            $text($raw, 'shortDescription'),
            $strings($raw['categories'] ?? null),
            $strings($raw['tags'] ?? null),
            $text($raw, 'releaseDate') ?: null,
            array_values(array_filter(array_map(GalleryVersion::fromGallery(...), \is_array($raw['versions'] ?? null) ? $raw['versions'] : []))),
        );
    }
}
