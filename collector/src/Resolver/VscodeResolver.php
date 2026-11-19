<?php

namespace App\Resolver;

use App\Blacklist\Blacklist;
use App\Entity\Artifact;
use App\Entity\PackageList;
use App\Http\Downloader;
use App\Job\JobContext;
use App\Resolver\Vscode\Gallery;
use App\Resolver\Vscode\GalleryExtension;
use App\Resolver\Vscode\GalleryVersion;
use Sas\Bundle\Ecosystem;

/**
 * Extensions VS Code, depuis Open VSX et/ou le Visual Studio Marketplace (protocole de galerie commun).
 *
 *  - « éditeur.nom » : dernière version stable ; « éditeur.nom@1.2.3 » ou « éditeur.nom >=1.2 » : version choisie ;
 *  - une extension propre à chaque plateforme (rust-analyzer, C/C++…) est collectée pour chaque plateforme
 *    demandée (Linux x64 par défaut) ; une extension universelle, une seule fois ;
 *  - dépendances (extensionDependencies) et contenu des packs (extensionPack) suivis ;
 *  - VSIX vérifié par sa somme SHA-256 publiée ; README, icône, manifeste, notes de version et licence
 *    collectés aussi, pour que la marketplace de Romulus les affiche hors ligne.
 * Un .vscode/extensions.json (« recommendations ») peut être importé.
 */
final readonly class VscodeResolver implements Resolver
{
    public const string DEFAULT_SOURCE = 'openvsx';

    /** Ordre d'interrogation des galeries selon l'option « source » */
    public const array SOURCES = [
        'openvsx' => [Gallery::OPENVSX],
        'openvsx+marketplace' => [Gallery::OPENVSX, Gallery::MARKETPLACE],
        'marketplace+openvsx' => [Gallery::MARKETPLACE, Gallery::OPENVSX],
        'marketplace' => [Gallery::MARKETPLACE],
    ];

    public const array PLATFORMS = [
        'linux-x64' => 'Linux x64',
        'linux-arm64' => 'Linux ARM64',
        'linux-armhf' => 'Linux ARM 32 bits',
        'alpine-x64' => 'Alpine Linux x64',
        'alpine-arm64' => 'Alpine Linux ARM64',
        'win32-x64' => 'Windows x64',
        'win32-arm64' => 'Windows ARM64',
        'darwin-x64' => 'macOS Intel',
        'darwin-arm64' => 'macOS Apple Silicon',
        'web' => 'VS Code pour le Web',
    ];

    /** Fichiers affichés par la galerie de l'éditeur, collectés avec le VSIX */
    public const array ASSETS = [
        'Microsoft.VisualStudio.Code.Manifest',
        'Microsoft.VisualStudio.Services.Content.Details',
        'Microsoft.VisualStudio.Services.Content.Changelog',
        'Microsoft.VisualStudio.Services.Content.License',
        'Microsoft.VisualStudio.Services.Icons.Default',
    ];

    private const string NAME = '/^[a-z0-9][a-z0-9-]*\.[a-z0-9][a-z0-9_.-]*$/i';

    public function __construct(
        private Gallery $gallery,
        private Downloader $downloader,
        private ArtifactCatalog $catalog,
        private Blacklist $blacklist,
    ) {
    }

    public function ecosystem(): Ecosystem
    {
        return Ecosystem::Vscode;
    }

    /** @return array{string, string} identifiant, contrainte (« * » = dernière stable) */
    public static function parseSpec(string $line): array
    {
        $line = trim($line);
        if (preg_match('/^([^\s@]+)@(\S+)$/', $line, $m)) {
            return [strtolower($m[1]), $m[2]];
        }
        $parts = preg_split('/\s+/', $line, 2) ?: [$line];

        return [strtolower($parts[0]), trim($parts[1] ?? '') ?: '*'];
    }

    /** @return list<string> */
    public static function platforms(PackageList $list): array
    {
        $platforms = $list->getOptions()['platforms'] ?? [];
        $platforms = \is_array($platforms) ? array_values(array_filter($platforms, \is_string(...))) : [];

        return [] !== $platforms ? $platforms : ['linux-x64'];
    }

    public static function source(PackageList $list): string
    {
        $source = $list->getOptions()['source'] ?? self::DEFAULT_SOURCE;

        return \is_string($source) ? $source : self::DEFAULT_SOURCE;
    }

    public static function vscodeVersion(PackageList $list): string
    {
        $version = $list->getOptions()['vscodeVersion'] ?? '';

        return \is_string($version) ? trim($version) : '';
    }

    public function validate(PackageList $list): array
    {
        $errors = [];
        foreach ($this->requirements($list) as [$id, $constraint]) {
            if (!preg_match(self::NAME, $id)) {
                $errors[] = sprintf('« %s » : attendu éditeur.nom (identifiant affiché dans VS Code, ex. redhat.vscode-yaml ou ms-python.python@2024.2.0).', $id);
            } elseif (!Constraint::isValid($constraint)) {
                $errors[] = sprintf('« %s %s » : version ou contrainte invalide (ex. 1.2.3, >=1.2.0).', $id, $constraint);
            }
        }
        foreach (self::platforms($list) as $platform) {
            if (!isset(self::PLATFORMS[$platform])) {
                $errors[] = sprintf('Plateforme inconnue : %s.', $platform);
            }
        }
        if (!isset(self::SOURCES[self::source($list)])) {
            $errors[] = 'Source inconnue : choisissez Open VSX, le Visual Studio Marketplace, ou les deux.';
        }
        $vscode = self::vscodeVersion($list);
        if ('' !== $vscode && !preg_match('/^\d+\.\d+(\.\d+)?$/', $vscode)) {
            $errors[] = sprintf('Version de VS Code invalide : « %s » (ex. 1.95 ou 1.95.3).', $vscode);
        }
        if (null !== $list->getLockfile()) {
            try {
                self::parseExtensionsJson($list->getLockfile());
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ([] === $this->requirements($list)) {
            $errors[] = 'La liste est vide : saisissez des extensions (redhat.vscode-yaml) ou importez un .vscode/extensions.json.';
        }

        return $errors;
    }

    public function resolve(PackageList $list, JobContext $context): array
    {
        $platforms = self::platforms($list);
        $sources = self::SOURCES[self::source($list)] ?? self::SOURCES[self::DEFAULT_SOURCE];
        $vscode = self::vscodeVersion($list);
        $preRelease = true === ($list->getOptions()['preRelease'] ?? false);

        /** @var list<array{string, string, ?string}> $queue identifiant, contrainte, demandée par */
        $queue = array_map(static fn (array $r): array => [$r[0], $r[1], null], $this->requirements($list));
        $seen = [];
        $artifacts = [];
        while (null !== $item = array_shift($queue)) {
            [$id, $constraint, $by] = $item;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            // Extensions intégrées à VS Code (vscode.git, vscode.typescript-language-features…)
            if (str_starts_with($id, 'vscode.')) {
                $context->log(sprintf('%s : extension intégrée à VS Code, rien à collecter.', $id));
                continue;
            }
            $context->report(sprintf('Extension %s', $id));
            [$source, $extension] = $this->find($id, $sources, $by);
            $builds = self::choose($extension->versions, $constraint, $platforms, $vscode, $preRelease);
            if (null === $builds) {
                throw new \RuntimeException(sprintf('Aucune version de %s ne convient (%s%s%s ; plateformes %s)%s.', $id, '*' === $constraint ? 'dernière stable' : $constraint, '' !== $vscode ? ', VS Code '.$vscode : '', $preRelease ? ', préversions comprises' : '', implode(', ', $platforms), null !== $by ? ' — demandée par '.$by : ''));
            }
            $version = $builds[0]->version;
            if ($this->blacklist->blocks(Ecosystem::Vscode, $extension->id, $version, $context)) {
                continue;
            }
            $covered = array_map(static fn (GalleryVersion $b) => $b->targetPlatform, $builds);
            if (!\in_array('universal', $covered, true) && [] !== $missing = array_diff($platforms, $covered)) {
                $context->log(sprintf('ATTENTION %s %s n\'existe pas pour : %s.', $extension->id, $version, implode(', ', $missing)));
            }
            foreach ($builds as $build) {
                $artifacts[] = $this->collect($source, $extension, $build, $context);
            }
            foreach ([...$builds[0]->dependencies, ...$builds[0]->extensionPack] as $dependency) {
                $queue[] = [strtolower($dependency), '*', $extension->id];
            }
        }
        $this->catalog->flush();
        $context->log(sprintf('%d extension(s), dépendances et packs compris ; %d fichier(s) VSIX.', \count($seen), \count($artifacts)));

        return $artifacts;
    }

    /**
     * Constructions retenues : la plus récente version qui respecte la contrainte, le moteur VS Code demandé
     * et l'option préversions ; pour chaque plateforme voulue, sa construction propre, sinon l'universelle.
     *
     * @param list<GalleryVersion> $versions
     * @param list<string>         $platforms
     *
     * @return non-empty-list<GalleryVersion>|null
     */
    public static function choose(array $versions, string $constraint, array $platforms, string $vscode, bool $preRelease): ?array
    {
        $wanted = [...$platforms, 'universal'];
        $candidates = [];
        foreach ($versions as $v) {
            if (!\in_array($v->targetPlatform, $wanted, true) || ($v->preRelease && !$preRelease)) {
                continue;
            }
            if ('' !== $vscode && !self::engineAccepts($v->engine, $vscode)) {
                continue;
            }
            $candidates[$v->version][] = $v;
        }
        $best = Constraint::best(array_map(strval(...), array_keys($candidates)), $constraint);
        if (null === $best) {
            return null;
        }
        // Comme VS Code : pour chaque plateforme, sa construction propre, sinon l'universelle
        $byPlatform = [];
        foreach ($candidates[$best] as $build) {
            $byPlatform[$build->targetPlatform] ??= $build;
        }
        $chosen = [];
        foreach ($platforms as $platform) {
            $build = $byPlatform[$platform] ?? $byPlatform['universal'] ?? null;
            if (null !== $build) {
                $chosen[$build->targetPlatform] = $build;
            }
        }

        return [] === $chosen ? null : array_values($chosen);
    }

    /** Le moteur requis par l'extension (« ^1.91.0 ») accepte-t-il cette version de VS Code ? */
    public static function engineAccepts(string $engine, string $vscode): bool
    {
        $engine = trim((string) preg_replace('/-[a-z][\w.]*/i', '', $engine));
        if ('' === $engine || '*' === $engine) {
            return true;
        }
        $vscode = 1 === substr_count($vscode, '.') ? $vscode.'.0' : $vscode;

        return !Constraint::isValid($engine) || null !== Constraint::best([$vscode], $engine);
    }

    /**
     * Première galerie qui connaît l'extension.
     *
     * @param list<string> $sources
     *
     * @return array{string, GalleryExtension}
     */
    private function find(string $id, array $sources, ?string $by): array
    {
        foreach ($sources as $source) {
            if (null !== $extension = $this->gallery->extension($source, $id)) {
                return [$source, $extension];
            }
        }

        throw new \RuntimeException(sprintf('Extension introuvable sur %s : %s%s.', implode(' ni sur ', array_map(Gallery::label(...), $sources)), $id, null !== $by ? ' (demandée par '.$by.')' : ''));
    }

    private function collect(string $source, GalleryExtension $extension, GalleryVersion $build, JobContext $context): Artifact
    {
        $platform = $build->targetPlatform;
        $label = sprintf('%s %s%s', $extension->id, $build->version, 'universal' === $platform ? '' : ' ('.$platform.')');
        $filename = sprintf('%s-%s%s.vsix', strtolower($extension->id), $build->version, 'universal' === $platform ? '' : '@'.$platform);
        if ($existing = $this->catalog->complete(Ecosystem::Vscode, strtolower($extension->id), $build->version, $filename)) {
            return $existing;
        }
        $url = $build->file(Gallery::VSIX) ?? throw new \RuntimeException(sprintf('VSIX introuvable pour %s.', $label));
        $sha256 = $this->gallery->sha256($source, $build);
        if (null === $sha256) {
            $context->log(sprintf('%s : aucune somme publiée par %s, VSIX reçu par HTTPS sans vérification d\'empreinte.', $label, Gallery::label($source)));
        }
        $context->report('Téléchargement '.$label);
        $blobs = [$this->downloader->fetch($url, sha256: $sha256)];
        $assets = [];
        foreach (self::ASSETS as $type) {
            $asset = $build->file($type);
            if (null === $asset) {
                continue;
            }
            try {
                $blob = $this->downloader->fetch($asset);
                $blobs[] = $blob;
                $assets[$type] = $blob['sha256'];
            } catch (\Throwable $e) {
                $context->log(sprintf('%s : %s non récupéré (%s).', $label, $type, $e->getMessage()));
            }
        }

        return $this->catalog->record(Ecosystem::Vscode, strtolower($extension->id), $build->version, $filename, $blobs, [
            'publisher' => $extension->publisher,
            'name' => $extension->name,
            'displayName' => $extension->displayName,
            'description' => $extension->description,
            'publisherDisplayName' => $extension->publisherDisplayName,
            'extensionId' => $extension->extensionId,
            'publisherId' => $extension->publisherId,
            'categories' => $extension->categories,
            'tags' => $extension->tags,
            'releaseDate' => $extension->releaseDate,
            'lastUpdated' => $build->lastUpdated,
            'targetPlatform' => $platform,
            'preRelease' => $build->preRelease,
            'engine' => $build->engine,
            'dependencies' => $build->dependencies,
            'extensionPack' => $build->extensionPack,
            'source' => $source,
            // Propriétés renvoyées telles quelles par la galerie de Romulus (moteur, préversion, liens…)
            'properties' => (object) array_filter($build->properties, static fn (string $key): bool => !str_contains($key, 'VsixSha256'), \ARRAY_FILTER_USE_KEY),
            'assets' => (object) $assets,
        ]);
    }

    /** @return list<array{string, string}> */
    private function requirements(PackageList $list): array
    {
        $items = array_map(self::parseSpec(...), $list->getSpecLines());
        if (null !== $list->getLockfile()) {
            try {
                foreach (self::parseExtensionsJson($list->getLockfile()) as $id) {
                    $items[] = [$id, '*'];
                }
            } catch (\InvalidArgumentException) {
            }
        }

        return $items;
    }

    /**
     * .vscode/extensions.json (JSON avec commentaires) : identifiants de « recommendations ».
     *
     * @return list<string>
     */
    public static function parseExtensionsJson(string $json): array
    {
        // Commentaires et virgules finales admis par VS Code (hors chaînes)
        $clean = (string) preg_replace(['~"(?:\\\\.|[^"\\\\])*"(*SKIP)(*F)|//[^\n]*|/\*.*?\*/~s', '/,(\s*[}\]])/'], ['', '$1'], $json);
        $data = json_decode($clean, true);
        if (!\is_array($data) || !\is_array($data['recommendations'] ?? null)) {
            throw new \InvalidArgumentException('extensions.json illisible : attendu { "recommendations": ["éditeur.nom", …] }.');
        }
        $ids = array_map(static fn (string $id): string => strtolower(trim($id)), array_filter($data['recommendations'], \is_string(...)));

        return array_values(array_unique($ids));
    }
}
