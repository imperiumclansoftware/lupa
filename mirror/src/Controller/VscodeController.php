<?php

namespace App\Controller;

use App\Entity\Package;
use App\Quarantine\Quarantine;
use App\Registry\Files;
use App\Registry\VscodeGallery;
use App\Repository\PackageRepository;
use Sas\Bundle\Ecosystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Marketplace d'extensions VS Code (lecture seule), au protocole de galerie de VS Code — le même
 * qu'Open VSX. À déclarer dans « extensionsGallery » (VS Code : product.json ; VSCodium : variables
 * VSCODE_GALLERY_* ; code-server : EXTENSIONS_GALLERY) :
 *   serviceUrl          http://miroir/vscode/gallery
 *   itemUrl             http://miroir/vscode/item
 *   resourceUrlTemplate http://miroir/vscode/unpkg/{publisher}/{name}/{version}/{path}
 *   extensionUrlTemplate http://miroir/vscode/gallery/{publisher}/{name}/latest
 */
final class VscodeController
{
    private const string NAME = '[A-Za-z0-9][A-Za-z0-9_.-]*';

    /** Critères de recherche du protocole */
    private const int TAG = 1;
    private const int EXTENSION_ID = 4;
    private const int CATEGORY = 5;
    private const int EXTENSION_NAME = 7;
    private const int SEARCH_TEXT = 10;
    private const int LATEST_ONLY = 0x200;

    public function __construct(
        private readonly VscodeGallery $gallery,
        private readonly PackageRepository $packages,
        private readonly Quarantine $quarantine,
        private readonly Files $files,
    ) {
    }

    /** Recherche, mises à jour et détails : l'éditeur envoie des critères, la galerie répond par page. */
    #[Route('/vscode/gallery/extensionquery', methods: ['POST'])]
    public function query(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $filters = \is_array($body) && \is_array($body['filters'] ?? null) ? $body['filters'] : [];
        $filter = \is_array($filters[0] ?? null) ? $filters[0] : [];
        $flags = \is_array($body) && is_numeric($body['flags'] ?? null) ? (int) $body['flags'] : 0;

        $names = $ids = $words = $categories = $tags = [];
        foreach (\is_array($filter['criteria'] ?? null) ? $filter['criteria'] : [] as $criterion) {
            $value = \is_array($criterion) && \is_scalar($criterion['value'] ?? null) ? trim((string) $criterion['value']) : '';
            match (\is_array($criterion) && is_numeric($criterion['filterType'] ?? null) ? (int) $criterion['filterType'] : 0) {
                self::EXTENSION_NAME => $names[] = strtolower($value),
                self::EXTENSION_ID => $ids[] = strtolower($value),
                self::SEARCH_TEXT => $words[] = strtolower($value),
                self::CATEGORY => $categories[] = strtolower($value),
                self::TAG => $tags[] = strtolower($value),
                default => null,
            };
        }

        $matches = [];
        foreach ($this->gallery->catalog() as $id => $builds) {
            $metadata = $builds[0]->getMetadata();
            $extensionId = strtolower(\is_string($metadata['extensionId'] ?? null) ? $metadata['extensionId'] : '');
            if (([] !== $names || [] !== $ids) && !\in_array($id, $names, true) && ('' === $extensionId || !\in_array($extensionId, $ids, true))) {
                continue;
            }
            if ([] !== $categories && [] === array_intersect($categories, array_map(strtolower(...), self::strings($metadata['categories'] ?? null)))) {
                continue;
            }
            if ([] !== $tags && [] === array_intersect($tags, array_map(strtolower(...), self::strings($metadata['tags'] ?? null)))) {
                continue;
            }
            if ([] !== $words && !self::matchesWords($id, $metadata, $words)) {
                continue;
            }
            $matches[] = $builds;
        }

        $size = max(1, min(100, is_numeric($filter['pageSize'] ?? null) ? (int) $filter['pageSize'] : 50));
        $page = max(1, is_numeric($filter['pageNumber'] ?? null) ? (int) $filter['pageNumber'] : 1);
        $base = $this->files->baseUrl($request);

        return new JsonResponse(['results' => [[
            'extensions' => array_map(fn (array $builds) => $this->gallery->extension($builds, $base, 0 !== ($flags & self::LATEST_ONLY)), \array_slice($matches, ($page - 1) * $size, $size)),
            'pagingToken' => null,
            'resultMetadata' => [
                ['metadataType' => 'ResultCount', 'metadataItems' => [['name' => 'TotalCount', 'count' => \count($matches)]]],
            ],
        ]]]);
    }

    /** extensionUrlTemplate : dernière version d'une extension (toutes ses plateformes). */
    #[Route('/vscode/gallery/{publisher}/{name}/latest', methods: ['GET', 'HEAD'], requirements: ['publisher' => self::NAME, 'name' => self::NAME])]
    public function latest(Request $request, string $publisher, string $name): JsonResponse
    {
        $builds = $this->gallery->catalog()[strtolower($publisher.'.'.$name)] ?? null;
        if (null === $builds) {
            return new JsonResponse(['error' => sprintf('Extension %s.%s absente du miroir hors ligne.', $publisher, $name)], 404);
        }

        return new JsonResponse($this->gallery->extension($builds, $this->files->baseUrl($request), true));
    }

    /** Fichiers d'une version (VSIX, README, icône, manifeste…), comme le Marketplace. */
    #[Route('/vscode/asset/{publisher}/{name}/{version}/{assetType}', methods: ['GET', 'HEAD'], requirements: ['publisher' => self::NAME, 'name' => self::NAME, 'version' => '[^/]+', 'assetType' => '[A-Za-z0-9.]+'])]
    public function asset(Request $request, string $publisher, string $name, string $version, string $assetType): Response
    {
        $package = $this->package($publisher, $name, $version, $request->query->getString('targetPlatform') ?: null);
        if ($package instanceof Response) {
            return $package;
        }
        $asset = $this->gallery->asset($package, $assetType);
        if (null === $asset) {
            return self::text(sprintf("%s absent pour %s.%s %s.\n", $assetType, $publisher, $name, $version), 404);
        }
        $type = self::contentType($assetType, $asset);
        if (isset($asset['sha256'])) {
            return $this->files->serve($asset['sha256'], $type);
        }

        return new Response($asset['content'], 200, ['Content-Type' => $type]);
    }

    /** Ancienne adresse de téléchargement du Marketplace, encore utilisée par certains clients. */
    #[Route('/vscode/gallery/publishers/{publisher}/vsextensions/{name}/{version}/vspackage', methods: ['GET', 'HEAD'], requirements: ['publisher' => self::NAME, 'name' => self::NAME, 'version' => '[^/]+'])]
    public function vspackage(Request $request, string $publisher, string $name, string $version): Response
    {
        return $this->asset($request, $publisher, $name, $version, VscodeGallery::VSIX);
    }

    /** resourceUrlTemplate : fichier du dossier « extension/ » du VSIX (images du README, extensions web). */
    #[Route('/vscode/unpkg/{publisher}/{name}/{version}/{path}', methods: ['GET', 'HEAD'], requirements: ['publisher' => self::NAME, 'name' => self::NAME, 'version' => '[^/]+', 'path' => '.+'])]
    public function unpkg(string $publisher, string $name, string $version, string $path): Response
    {
        $package = $this->package($publisher, $name, $version, null);
        if ($package instanceof Response) {
            return $package;
        }
        $content = $this->gallery->fromVsix($package, 'extension/'.$path);
        if (null === $content) {
            return self::text("Fichier absent de l'extension.\n", 404);
        }
        $type = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($content) ?: 'application/octet-stream';
        if (str_ends_with(strtolower($path), '.svg')) {
            $type = 'image/svg+xml';
        }

        // Contenu fourni par l'extension : affiché, jamais exécuté dans l'origine du miroir
        return new Response($content, 200, ['Content-Type' => $type, 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox"]);
    }

    /** itemUrl : page d'une extension dans l'interface de Romulus. */
    #[Route('/vscode/item', methods: ['GET', 'HEAD'])]
    public function item(Request $request): Response
    {
        $item = strtolower($request->query->getString('itemName'));
        if (!preg_match('/^'.self::NAME.'$/', $item)) {
            return self::text("Paramètre itemName attendu (éditeur.nom).\n", 400);
        }

        return new RedirectResponse('/paquets/vscode/'.rawurlencode($item));
    }

    /** Statistiques d'installation envoyées par l'éditeur : acceptées, non conservées. */
    #[Route('/vscode/gallery/publishers/{publisher}/extensions/{name}/{version}/stats', methods: ['POST'], requirements: ['publisher' => self::NAME, 'name' => self::NAME, 'version' => '[^/]+'])]
    public function stats(): Response
    {
        return new Response('', 204);
    }

    private function package(string $publisher, string $name, string $version, ?string $platform): Package|Response
    {
        $package = $this->gallery->find($publisher.'.'.$name, $version, $platform);
        if (null !== $package) {
            return $package;
        }
        // Absente du catalogue : en quarantaine, ou jamais importée
        foreach ($this->packages->findBy(['ecosystem' => Ecosystem::Vscode, 'name' => strtolower($publisher.'.'.$name), 'version' => $version]) as $quarantined) {
            if ($entry = $this->quarantine->forPackage($quarantined)) {
                return self::text(sprintf("%s.%s %s est en quarantaine (liste noire)%s\n", $publisher, $name, $version, '' !== $entry->getReason() ? ' : '.$entry->getReason() : ''), 403);
            }
        }

        return self::text(sprintf("%s.%s %s absente du miroir hors ligne.\n", $publisher, $name, $version), 404);
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<string>         $words
     */
    private static function matchesWords(string $id, array $metadata, array $words): bool
    {
        $haystack = strtolower(implode(' ', [$id, ...array_map(static fn (string $k) => \is_string($metadata[$k] ?? null) ? $metadata[$k] : '', ['displayName', 'description', 'publisherDisplayName']), ...self::strings($metadata['tags'] ?? null)]));
        foreach ($words as $text) {
            foreach (preg_split('/\s+/', $text) ?: [] as $word) {
                // Filtres de la vue Extensions (@category:…, @installed…) : non pris en charge, ignorés
                if ('' !== $word && !str_starts_with($word, '@') && !str_contains($haystack, trim($word, '"'))) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array{sha256: string}|array{content: string} $asset */
    private static function contentType(string $assetType, array $asset): string
    {
        return match (true) {
            VscodeGallery::VSIX === $assetType => 'application/octet-stream',
            str_contains($assetType, 'Manifest') && !str_contains($assetType, 'Vsix') => 'application/json',
            str_contains($assetType, 'VsixManifest') => 'text/xml',
            str_contains($assetType, 'Icons') => isset($asset['content']) && str_contains($asset['content'], '<svg') ? 'image/svg+xml' : 'image/png',
            default => 'text/plain; charset=utf-8',
        };
    }

    /** @return list<string> */
    private static function strings(mixed $list): array
    {
        return array_values(array_filter(\is_array($list) ? $list : [], \is_string(...)));
    }

    private static function text(string $message, int $status): Response
    {
        return new Response($message, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
