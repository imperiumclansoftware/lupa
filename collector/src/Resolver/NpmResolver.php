<?php

namespace App\Resolver;

use App\Entity\Artifact;
use App\Entity\PackageList;
use App\Http\Downloader;
use App\Job\JobContext;
use App\Template\TemplateCatalog;
use App\Template\TemplateGenerator;
use Sas\Bundle\Ecosystem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * npm : l'arbre complet est calculé par npm lui-même (`npm install --package-lock-only`), y compris
 * les dépendances optionnelles de toutes les plateformes (@esbuild/linux-x64, @esbuild/win32-x64…).
 * Un package-lock.json importé est utilisé tel quel.
 */
final readonly class NpmResolver implements Resolver
{
    private const string NAME = '/^(@[a-z0-9][a-z0-9._~-]*\/)?[a-z0-9_][a-z0-9._~-]*$/';

    public function __construct(
        private Downloader $downloader,
        private ArtifactCatalog $catalog,
        private HttpClientInterface $http,
        private TemplateGenerator $templates,
        #[Autowire('%env(NPM_REGISTRY)%')]
        private string $registry,
    ) {
    }

    public function ecosystem(): Ecosystem
    {
        return Ecosystem::Npm;
    }

    public function validate(PackageList $list): array
    {
        $errors = [];
        foreach ($list->getSpecLines() as $line) {
            [$name] = self::parseSpec($line);
            if (!preg_match(self::NAME, $name)) {
                $errors[] = sprintf('« %s » : nom de paquet npm invalide (attendu : nom@version, ex. lodash@^4.17).', $line);
            }
        }
        if (null !== $list->getLockfile()) {
            try {
                $this->lockEntries($list->getLockfile(), true);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        $template = $list->getOptions()['template'] ?? null;
        $errors = [...$errors, ...TemplateCatalog::validate(Ecosystem::Npm, $template)];
        if ([] === $list->getSpecLines() && null === $list->getLockfile() && null === $template) {
            $errors[] = 'La liste est vide : choisissez un modèle, saisissez des paquets ou importez un package-lock.json.';
        }

        return $errors;
    }

    public function resolve(PackageList $list, JobContext $context): array
    {
        $includeDev = (bool) ($list->getOptions()['includeDev'] ?? true);
        $entries = [];

        if (null !== $list->getLockfile()) {
            $context->report('Lecture de '.($list->getLockfileName() ?? 'package-lock.json'));
            $entries = $this->lockEntries($list->getLockfile(), $includeDev);
            $context->log(sprintf('%d paquets dans le fichier de verrouillage.', \count($entries)));
        }

        // Modèle de framework : projet généré par le vrai outil (ng new, create-vite…), puis ses dépendances
        $specs = $list->getSpecLines();
        if ($generated = $this->templates->generate($list, $context)) {
            foreach ($generated['lockfiles'] as $lockfile) {
                $entries += $this->lockEntries($lockfile, true);
            }
            $specs = [...$specs, ...$generated['specs']];
            $context->log(sprintf('Projet généré : %d paquets.', \count($entries)));
        }

        if ([] !== $specs) {
            $context->report('Résolution des dépendances par npm');
            $entries += $this->resolveSpecs($specs, $list, $context);
        }

        ksort($entries);
        $total = \count($entries);
        $context->log(sprintf('%d paquets (dépendances comprises).', $total));

        // Métadonnées des versions à télécharger, 16 requêtes à la fois
        $missing = array_filter($entries, fn (array $e) => !$this->catalog->complete(Ecosystem::Npm, $e['name'], $e['version']));
        $context->log(sprintf('%d déjà présents, %d à télécharger.', $total - \count($missing), \count($missing)));
        $documents = $this->fetchVersionDocuments($missing, $context);

        $artifacts = [];
        $done = 0;
        foreach ($entries as $key => $entry) {
            ++$done;
            $artifact = $this->catalog->complete(Ecosystem::Npm, $entry['name'], $entry['version']);
            if (!$artifact) {
                $context->report(sprintf('Téléchargement %s@%s', $entry['name'], $entry['version']), (int) ($done * 100 / max(1, $total)));
                $artifact = $this->download($entry, $documents[$key] ?? null);
            }
            $artifacts[] = $artifact;
            if (0 === $done % 50) {
                $this->catalog->flush();
            }
        }
        $this->catalog->flush();

        return $artifacts;
    }

    /** @return array{string, string} nom, version demandée */
    public static function parseSpec(string $line): array
    {
        $at = strrpos($line, '@');
        if (false === $at || 0 === $at) {
            return [strtolower($line), 'latest'];
        }

        return [strtolower(substr($line, 0, $at)), trim(substr($line, $at + 1)) ?: 'latest'];
    }

    /**
     * @param list<string> $specs
     *
     * @return array<string, array{name: string, version: string, resolved: string|null, integrity: string|null}>
     */
    private function resolveSpecs(array $specs, PackageList $list, JobContext $context): array
    {
        $dependencies = [];
        foreach ($specs as $line) {
            [$name, $range] = self::parseSpec($line);
            $dependencies[$name] = $range;
        }

        $dir = Tool::tempDir('sas-npm');
        try {
            file_put_contents($dir.'/package.json', json_encode([
                'name' => 'sas-resolution',
                'version' => '0.0.0',
                'private' => true,
                'dependencies' => $dependencies,
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            $command = ['npm', 'install', '--package-lock-only', '--ignore-scripts', '--no-audit', '--no-fund', '--registry', $this->registry];
            if ($list->getOptions()['legacyPeerDeps'] ?? false) {
                $command[] = '--legacy-peer-deps';
            }
            try {
                Tool::run($command, $dir, $context);
            } catch (\RuntimeException $e) {
                if (\in_array('--legacy-peer-deps', $command, true) || !str_contains($e->getMessage(), 'ERESOLVE')) {
                    throw $e;
                }
                $context->log('Conflit de dépendances « peer » : nouvelle tentative avec --legacy-peer-deps.');
                $command[] = '--legacy-peer-deps';
                Tool::run($command, $dir, $context);
            }

            return $this->lockEntries((string) file_get_contents($dir.'/package-lock.json'), true);
        } finally {
            Tool::removeDir($dir);
        }
    }

    /**
     * @return array<string, array{name: string, version: string, resolved: string|null, integrity: string|null}>
     */
    private function lockEntries(string $json, bool $includeDev): array
    {
        try {
            $lock = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Le fichier importé n\'est pas un package-lock.json valide (JSON illisible).');
        }
        if (!\is_array($lock) || (!isset($lock['packages']) && !isset($lock['dependencies']))) {
            throw new \InvalidArgumentException('Le fichier importé n\'est pas un package-lock.json (ni « packages » ni « dependencies »).');
        }

        $entries = [];
        $add = static function (string $name, array $entry) use (&$entries, $includeDev): void {
            // Liens locaux, paquets embarqués dans un autre paquet, dépendances git : rien à télécharger
            if (!empty($entry['link']) || !empty($entry['inBundle']) || !empty($entry['bundled']) || !isset($entry['version'])
                || (!$includeDev && !empty($entry['dev']))) {
                return;
            }
            $resolved = $entry['resolved'] ?? null;
            if (null !== $resolved && !preg_match('#^https?://#', $resolved)) {
                return;
            }
            $version = (string) $entry['version'];
            if (str_starts_with($version, 'npm:')) {
                // Alias : "node_modules/foo": { "version": "npm:bar@1.0.0" } (lockfile v1)
                [$name, $version] = self::parseSpec(substr($version, 4));
            }
            if (!preg_match('/^\d+\.\d+\.\d+/', $version)) {
                return;
            }
            $entries[$name.'@'.$version] = [
                'name' => $name,
                'version' => $version,
                'resolved' => $resolved,
                'integrity' => $entry['integrity'] ?? null,
            ];
        };

        if (isset($lock['packages'])) {
            foreach ($lock['packages'] as $path => $entry) {
                if ('' === $path || !str_contains($path, 'node_modules/')) {
                    continue;
                }
                $name = $entry['name'] ?? substr($path, strrpos($path, 'node_modules/') + 13);
                $add($name, $entry);
            }
        } else {
            $walk = static function (array $dependencies) use (&$walk, $add): void {
                foreach ($dependencies as $name => $entry) {
                    $add($name, $entry);
                    $walk($entry['dependencies'] ?? []);
                }
            };
            $walk($lock['dependencies']);
        }

        return $entries;
    }

    /**
     * @param array<string, array{name: string, version: string}> $entries
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchVersionDocuments(array $entries, JobContext $context): array
    {
        $documents = [];
        $done = 0;
        foreach (array_chunk($entries, 16, true) as $chunk) {
            $responses = [];
            foreach ($chunk as $key => $entry) {
                $responses[$key] = $this->http->request('GET', sprintf('%s/%s/%s', rtrim($this->registry, '/'), $entry['name'], rawurlencode($entry['version'])), [
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => 60,
                ]);
            }
            foreach ($responses as $key => $response) {
                if (200 !== $response->getStatusCode()) {
                    throw new \RuntimeException(sprintf('Version introuvable sur le registre : %s (HTTP %d).', $key, $response->getStatusCode()));
                }
                $documents[$key] = $response->toArray();
            }
            $done += \count($chunk);
            $context->report(sprintf('Métadonnées : %d / %d', $done, \count($entries)));
        }

        return $documents;
    }

    /**
     * @param array{name: string, version: string, resolved: string|null, integrity: string|null} $entry
     * @param array<string, mixed>|null                                                            $document
     */
    private function download(array $entry, ?array $document): Artifact
    {
        if (null === $document) {
            throw new \LogicException(sprintf('Métadonnées manquantes pour %s@%s.', $entry['name'], $entry['version']));
        }
        $dist = $document['dist'] ?? [];
        $url = $dist['tarball'] ?? $entry['resolved'];
        if (!\is_string($url)) {
            throw new \RuntimeException(sprintf('Aucune archive publiée pour %s@%s.', $entry['name'], $entry['version']));
        }

        $checks = [];
        $integrity = $dist['integrity'] ?? $entry['integrity'];
        if (\is_string($integrity) && preg_match('/(?:^|\s)sha512-([A-Za-z0-9+\/=]+)/', $integrity, $m)) {
            $checks['sha512'] = bin2hex((string) base64_decode($m[1], true));
        } elseif (\is_string($dist['shasum'] ?? null)) {
            $checks['sha1'] = $dist['shasum'];
        }

        $blob = $this->downloader->fetch($url, checks: $checks);

        return $this->catalog->record(Ecosystem::Npm, $entry['name'], $entry['version'], null, [$blob], self::cleanDocument($document, basename((string) parse_url($url, \PHP_URL_PATH))));
    }

    /**
     * Document de version allégé : ce dont npm a besoin pour installer (dépendances, bin, engines, os/cpu…).
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function cleanDocument(array $document, string $tarball): array
    {
        foreach (array_keys($document) as $key) {
            if ((str_starts_with($key, '_') && '_hasShrinkwrap' !== $key)
                || \in_array($key, ['readme', 'readmeFilename', 'maintainers', 'contributors', 'gitHead'], true)) {
                unset($document[$key]);
            }
        }
        $scripts = $document['scripts'] ?? [];
        if (\is_array($scripts) && array_intersect_key($scripts, array_flip(['preinstall', 'install', 'postinstall']))) {
            $document['hasInstallScript'] = true;
        }
        $document['dist'] = array_intersect_key($document['dist'] ?? [], array_flip(['integrity', 'shasum', 'fileCount', 'unpackedSize']));
        $document['dist']['tarballName'] = $tarball;

        return $document;
    }
}
