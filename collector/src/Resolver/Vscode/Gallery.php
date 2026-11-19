<?php

namespace App\Resolver\Vscode;

use App\Http\Downloader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Client du protocole de galerie de VS Code (« extensionquery »), parlé à l'identique par Open VSX
 * (/vscode/gallery) et par le Visual Studio Marketplace (/_apis/public/gallery).
 */
final readonly class Gallery
{
    public const string OPENVSX = 'openvsx';
    public const string MARKETPLACE = 'marketplace';
    public const string VSIX = 'Microsoft.VisualStudio.Services.VSIXPackage';

    /** Drapeaux de requête : versions, fichiers, catégories, propriétés, adresses, statistiques */
    private const int FLAGS = 0x1 | 0x2 | 0x4 | 0x10 | 0x80 | 0x100;
    private const int LATEST_ONLY = 0x200;

    public function __construct(
        private Downloader $downloader,
        #[Autowire('%env(OPENVSX_URL)%')]
        private string $openVsx,
        #[Autowire('%env(VSCODE_MARKETPLACE_URL)%')]
        private string $marketplace,
    ) {
    }

    public static function label(string $source): string
    {
        return self::MARKETPLACE === $source ? 'Visual Studio Marketplace' : 'Open VSX';
    }

    /** Extension « éditeur.nom », toutes versions comprises ; null si la galerie ne la connaît pas. */
    public function extension(string $source, string $id): ?GalleryExtension
    {
        foreach ($this->query($source, 7, $id, 1, self::FLAGS) as $extension) {
            if (strtolower($extension->id) === strtolower($id)) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * Recherche par mots (dernière version seulement).
     *
     * @return list<GalleryExtension>
     */
    public function search(string $source, string $text, int $limit): array
    {
        return $this->query($source, 10, $text, $limit, self::FLAGS | self::LATEST_ONLY);
    }

    /**
     * Somme SHA-256 publiée pour un VSIX : propriété du Marketplace, fichier .sha256 à côté du VSIX sur
     * Open VSX ; null si la source n'en publie pas.
     */
    public function sha256(string $source, GalleryVersion $version): ?string
    {
        $published = $version->properties['Microsoft.VisualStudio.Services.VsixSha256'] ?? null;
        if (null !== $published && preg_match('/^[a-f0-9]{64}$/i', $published)) {
            return strtolower($published);
        }
        $vsix = $version->file(self::VSIX);
        if (self::OPENVSX === $source && null !== $vsix && str_ends_with($vsix, '.vsix')) {
            $text = $this->downloader->text(substr($vsix, 0, -5).'.sha256');
            if (null !== $text && preg_match('/^\s*([a-f0-9]{64})\b/i', $text, $m)) {
                return strtolower($m[1]);
            }
        }

        return null;
    }

    /** @return list<GalleryExtension> */
    private function query(string $source, int $filterType, string $value, int $size, int $flags): array
    {
        $url = self::MARKETPLACE === $source
            ? rtrim($this->marketplace, '/').'/_apis/public/gallery/extensionquery'
            : rtrim($this->openVsx, '/').'/vscode/gallery/extensionquery';
        $data = $this->downloader->post($url, [
            'filters' => [[
                'criteria' => [['filterType' => $filterType, 'value' => $value], ['filterType' => 8, 'value' => 'Microsoft.VisualStudio.Code']],
                'pageNumber' => 1,
                'pageSize' => $size,
                'sortBy' => 0,
                'sortOrder' => 0,
            ]],
            'flags' => $flags,
        ], ['headers' => ['Accept' => 'application/json;api-version=3.0-preview.1']]);

        $results = \is_array($data['results'] ?? null) && \is_array($data['results'][0] ?? null) ? $data['results'][0] : [];

        return array_values(array_filter(array_map(GalleryExtension::fromGallery(...), \is_array($results['extensions'] ?? null) ? $results['extensions'] : [])));
    }
}
