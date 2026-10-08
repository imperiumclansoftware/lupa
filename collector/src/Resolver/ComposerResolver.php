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

/**
 * Composer : composer calcule les dépendances (`composer update --no-install`) ; on télécharge ensuite
 * l'archive (dist) de chaque paquet. Un composer.lock importé est utilisé tel quel, un composer.json
 * importé fournit ses exigences.
 */
final readonly class ComposerResolver implements Resolver
{
    private const string NAME = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/';

    public function __construct(
        private Downloader $downloader,
        private ArtifactCatalog $catalog,
        private TemplateGenerator $templates,
        private FlexRecipes $flex,
        #[Autowire('%env(GITHUB_TOKEN)%')]
        private string $githubToken,
    ) {
    }

    public function ecosystem(): Ecosystem
    {
        return Ecosystem::Composer;
    }

    /** @return array{string, string} nom, contrainte */
    public static function parseSpec(string $line): array
    {
        $parts = preg_split('/[\s:]+/', trim($line), 2) ?: [$line];

        return [strtolower($parts[0]), trim($parts[1] ?? '') ?: '*'];
    }

    public function validate(PackageList $list): array
    {
        $errors = [];
        foreach ($list->getSpecLines() as $line) {
            [$name] = self::parseSpec($line);
            if (!preg_match(self::NAME, $name)) {
                $errors[] = sprintf('« %s » : nom de paquet invalide (attendu : vendeur/paquet:contrainte, ex. monolog/monolog:^3.0).', $line);
            }
        }
        $php = $list->getOptions()['php'] ?? null;
        if (null !== $php && '' !== $php && !preg_match('/^\d+\.\d+(\.\d+)?$/', (string) $php)) {
            $errors[] = 'Version de PHP invalide (ex. 8.3).';
        }
        if (null !== $list->getLockfile()) {
            try {
                $this->parseLockfile($list->getLockfile());
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        $template = $list->getOptions()['template'] ?? null;
        $errors = [...$errors, ...TemplateCatalog::validate(Ecosystem::Composer, $template)];
        if ([] === $list->getSpecLines() && null === $list->getLockfile() && null === $template) {
            $errors[] = 'La liste est vide : choisissez un modèle, saisissez des paquets ou importez un composer.json / composer.lock.';
        }

        return $errors;
    }

    public function resolve(PackageList $list, JobContext $context): array
    {
        $includeDev = (bool) ($list->getOptions()['includeDev'] ?? false);
        $packages = [];
        $require = [];
        $stability = 'stable';

        foreach ($list->getSpecLines() as $line) {
            [$name, $constraint] = self::parseSpec($line);
            $require[$name] = $constraint;
        }

        if (null !== $list->getLockfile()) {
            $file = $this->parseLockfile($list->getLockfile());
            if (isset($file['packages'])) {
                $context->log('composer.lock importé : versions figées utilisées telles quelles.');
                foreach (array_merge($file['packages'], $includeDev ? $file['packages-dev'] ?? [] : []) as $package) {
                    $packages[$package['name'].'@'.$package['version']] = $package;
                }
            } else {
                foreach (['require', 'require-dev'] as $section) {
                    foreach ($file[$section] ?? [] as $name => $constraint) {
                        // Plateforme (php, ext-*, lib-*) et dépendances de développement si demandées
                        if (str_contains($name, '/') && ('require' === $section || $includeDev)) {
                            $require[strtolower($name)] = $constraint;
                        }
                    }
                }
                $stability = $file['minimum-stability'] ?? 'stable';
            }
        }

        // Modèle de framework : projet créé par composer create-project, dépendances de dev comprises
        if ($generated = $this->templates->generate($list, $context)) {
            foreach ($generated['lockfiles'] as $lockfile) {
                $lock = json_decode($lockfile, true);
                foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
                    $packages[$package['name'].'@'.$package['version']] = $package;
                }
            }
            foreach ($generated['specs'] as $line) {
                [$name, $constraint] = self::parseSpec($line);
                $require[$name] = $constraint;
            }
            $context->log(sprintf('Projet généré : %d paquets.', \count($packages)));
        }

        if ([] !== $require) {
            $context->report('Résolution des dépendances par Composer');
            foreach ($this->resolveRequirements($require, $stability, $list, $context) as $package) {
                $packages[$package['name'].'@'.$package['version']] = $package;
            }
        }

        ksort($packages);
        $context->log(sprintf('%d paquets (dépendances comprises).', \count($packages)));

        $artifacts = [];
        $done = 0;
        foreach ($packages as $package) {
            ++$done;
            $artifact = $this->catalog->complete(Ecosystem::Composer, $package['name'], $package['version']);
            if (!$artifact) {
                $context->report(sprintf('Téléchargement %s %s', $package['name'], $package['version']), (int) ($done * 100 / \count($packages)));
                $artifact = $this->download($package, $context);
            }
            if ($artifact) {
                $artifacts[] = $artifact;
            }
            if (0 === $done % 50) {
                $this->catalog->flush();
            }
        }
        $this->catalog->flush();

        // Projet Symfony : les recettes Flex sont nécessaires hors ligne (create-project, require…)
        $versions = array_column($packages, 'version', 'name');
        if (isset($versions['symfony/flex'])) {
            array_push($artifacts, ...$this->flex->collect($versions, $context));
        }

        return $artifacts;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseLockfile(string $json): array
    {
        try {
            $file = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Le fichier importé n\'est pas un JSON valide (composer.json ou composer.lock attendu).');
        }
        if (!\is_array($file) || (!isset($file['packages']) && !isset($file['require']) && !isset($file['require-dev']))) {
            throw new \InvalidArgumentException('Le fichier importé n\'est ni un composer.lock ni un composer.json.');
        }

        return $file;
    }

    /**
     * @param array<string, string> $require
     *
     * @return list<array<string, mixed>>
     */
    private function resolveRequirements(array $require, string $stability, PackageList $list, JobContext $context): array
    {
        $php = $list->getOptions()['php'] ?? null;
        $config = ['allow-plugins' => false, 'audit' => ['block-insecure' => false]];
        if ($php) {
            $config['platform'] = ['php' => $php];
        }

        $dir = Tool::tempDir('sas-composer');
        try {
            file_put_contents($dir.'/composer.json', json_encode([
                'name' => 'sas/resolution',
                'require' => $require,
                'minimum-stability' => $stability,
                'prefer-stable' => true,
                'config' => $config,
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            $command = ['composer', 'update', '--no-install', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress', '--no-audit'];
            // Avec une version de PHP cible, seules les extensions sont ignorées ; sinon toute la plateforme
            array_push($command, ...($php ? ['--ignore-platform-req=ext-*', '--ignore-platform-req=lib-*'] : ['--ignore-platform-reqs']));
            $env = ['COMPOSER_NO_INTERACTION' => '1'];
            if ('' !== $this->githubToken) {
                $env['COMPOSER_AUTH'] = json_encode(['github-oauth' => ['github.com' => $this->githubToken]]);
            }
            Tool::run($command, $dir, $context, $env);

            $lock = json_decode((string) file_get_contents($dir.'/composer.lock'), true);

            return $lock['packages'] ?? [];
        } finally {
            Tool::removeDir($dir);
        }
    }

    /** @param array<string, mixed> $package */
    private function download(array $package, JobContext $context): ?Artifact
    {
        $name = (string) $package['name'];
        $version = (string) $package['version'];
        $dist = $package['dist'] ?? null;

        $metadata = array_diff_key($package, array_flip(['source', 'dist', 'notification-url', 'installation-source', 'time']));
        if (isset($package['time'])) {
            $metadata['time'] = $package['time'];
        }

        if (!\is_array($dist) || empty($dist['url'])) {
            if ('metapackage' === ($package['type'] ?? null)) {
                return $this->catalog->record(Ecosystem::Composer, $name, $version, null, [], $metadata);
            }
            $context->log(sprintf('IGNORÉ %s %s : aucune archive (dist) publiée, uniquement des sources git.', $name, $version));

            return null;
        }

        $url = (string) $dist['url'];
        $options = [];
        // Archives GitHub : codeload n'est pas soumis aux limites de l'API
        if (preg_match('#^https://api\.github\.com/repos/([^/]+)/([^/]+)/zipball/(.+)$#', $url, $m)) {
            $url = sprintf('https://codeload.github.com/%s/%s/legacy.zip/%s', $m[1], $m[2], $m[3]);
        } elseif (str_starts_with($url, 'https://api.github.com/') && '' !== $this->githubToken) {
            $options['headers'] = ['Authorization' => 'Bearer '.$this->githubToken];
        }
        $checks = !empty($dist['shasum']) ? ['sha1' => (string) $dist['shasum']] : [];

        $blob = $this->downloader->fetch($url, $options, checks: $checks);
        $metadata['distType'] = $dist['type'] ?? 'zip';
        $metadata['distReference'] = $dist['reference'] ?? null;

        return $this->catalog->record(Ecosystem::Composer, $name, $version, null, [$blob], $metadata);
    }
}
