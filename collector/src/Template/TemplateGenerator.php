<?php

namespace App\Template;

use App\Entity\PackageList;
use App\Job\JobContext;
use App\Resolver\Tool;
use App\Source\Sources;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exécute le générateur d'un modèle de framework dans un dossier temporaire et renvoie ce qu'il faut
 * collecter : les fichiers de verrouillage produits et les paquets à ajouter (le générateur lui-même).
 */
final readonly class TemplateGenerator
{
    public function __construct(
        #[Autowire('%env(NPM_REGISTRY)%')]
        private string $registry,
        #[Autowire('%env(GITHUB_TOKEN)%')]
        private string $githubToken,
        private ?Sources $sources = null,
    ) {
    }

    /** @return array{lockfiles: list<string>, specs: list<string>}|null */
    public function generate(PackageList $list, JobContext $context): ?array
    {
        $option = $list->getOptions()['template'] ?? null;
        if (!\is_array($option) || !($template = TemplateCatalog::find((string) ($option['id'] ?? '')))) {
            return null;
        }
        $version = (string) $option['version'];
        $context->log(sprintf('Modèle %s (%s) : exécution du générateur.', $template['label'], $version));

        $dir = Tool::tempDir('sas-template');
        try {
            return match (true) {
                'angular' === $template['id'] => $this->angular($dir, $version, $context),
                str_starts_with($template['id'], 'vite-') => $this->vite($dir, substr($template['id'], 5), $version, $context),
                'nestjs' === $template['id'] => $this->nest($dir, $version, $context),
                'symfony' === $template['id'] => $this->symfony($dir, $version, $context),
                'laravel' === $template['id'] => $this->laravel($dir, $version, $context),
                default => throw new \LogicException('Générateur absent pour '.$template['id']),
            };
        } finally {
            Tool::removeDir($dir);
        }
    }

    /** @return array{lockfiles: list<string>, specs: list<string>} */
    private function angular(string $dir, string $version, JobContext $context): array
    {
        $lockfiles = [];
        // Avec et sans SSR : le rendu serveur ajoute @angular/ssr, express…
        foreach (['false' => 'app', 'true' => 'app-ssr'] as $ssr => $name) {
            $context->report(sprintf('ng new %s (SSR %s)', $name, 'true' === $ssr ? 'activé' : 'désactivé'));
            Tool::run(['npx', '-y', '@angular/cli@'.$version, 'new', $name, '--defaults', '--skip-git', '--skip-install',
                '--package-manager=npm', '--ssr='.$ssr], $dir, $context, $this->npmEnv() + ['NG_CLI_ANALYTICS' => 'false']);
            $lockfiles[] = $this->npmLock($dir.'/'.$name, $context);
        }

        return ['lockfiles' => $lockfiles, 'specs' => ['@angular/cli@'.$version]];
    }

    /** @return array{lockfiles: list<string>, specs: list<string>} */
    private function vite(string $dir, string $flavour, string $version, JobContext $context): array
    {
        // Le modèle est un dossier du paquet create-vite : on le lit sans lancer l'assistant interactif
        $context->report('Récupération de create-vite');
        Tool::run(['npm', 'install', '--no-save', '--ignore-scripts', '--no-audit', '--no-fund', '--prefix', $dir.'/gen',
            '--registry', $this->npmRegistry(), 'create-vite@'.$version], $dir, $context, $this->npmEnv());
        $source = sprintf('%s/gen/node_modules/create-vite/template-%s-ts/package.json', $dir, $flavour);
        if (!is_file($source)) {
            throw new \RuntimeException(sprintf('Modèle %s-ts absent de create-vite %s.', $flavour, $version));
        }
        mkdir($dir.'/app');
        $package = json_decode((string) file_get_contents($source), true);
        $package['name'] = 'app';
        file_put_contents($dir.'/app/package.json', json_encode($package, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        return ['lockfiles' => [$this->npmLock($dir.'/app', $context)], 'specs' => ['create-vite@'.$version]];
    }

    /** @return array{lockfiles: list<string>, specs: list<string>} */
    private function nest(string $dir, string $version, JobContext $context): array
    {
        $context->report('nest new app');
        Tool::run(['npx', '-y', '@nestjs/cli@'.$version, 'new', 'app', '--skip-git', '--skip-install', '--package-manager', 'npm'],
            $dir, $context, $this->npmEnv());

        return ['lockfiles' => [$this->npmLock($dir.'/app', $context)], 'specs' => ['@nestjs/cli@'.$version]];
    }

    /** @return array{lockfiles: list<string>, specs: list<string>} */
    private function symfony(string $dir, string $version, JobContext $context): array
    {
        $constraint = 'latest' === $version ? '*' : $version.'.*';
        $context->report('composer create-project symfony/skeleton');
        $this->composer(['create-project', 'symfony/skeleton:'.$constraint, 'app', '--no-install'], $dir, $context);
        // Application web complète : ce qu'ajoute « composer require webapp »
        $this->composer(['require', '--no-update', 'symfony/webapp-pack'], $dir.'/app', $context);

        return ['lockfiles' => [$this->composerLock($dir.'/app', $context)], 'specs' => ['symfony/skeleton:'.$constraint]];
    }

    /** @return array{lockfiles: list<string>, specs: list<string>} */
    private function laravel(string $dir, string $version, JobContext $context): array
    {
        $constraint = 'latest' === $version ? '*' : '^'.$version;
        $context->report('composer create-project laravel/laravel');
        $this->composer(['create-project', 'laravel/laravel:'.$constraint, 'app', '--no-install'], $dir, $context);

        return ['lockfiles' => [$this->composerLock($dir.'/app', $context)], 'specs' => ['laravel/laravel:'.$constraint]];
    }

    private function npmLock(string $project, JobContext $context): string
    {
        $context->report('Résolution des dépendances du projet généré');
        $command = ['npm', 'install', '--package-lock-only', '--ignore-scripts', '--no-audit', '--no-fund', '--registry', $this->npmRegistry()];
        try {
            Tool::run($command, $project, $context, $this->npmEnv());
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'ERESOLVE')) {
                throw $e;
            }
            Tool::run([...$command, '--legacy-peer-deps'], $project, $context, $this->npmEnv());
        }

        return (string) file_get_contents($project.'/package-lock.json');
    }

    private function composerLock(string $project, JobContext $context): string
    {
        // Ce que Flex ferait à l'installation (plugins désactivés ici) : flex-require devient require
        $file = $project.'/composer.json';
        $json = json_decode((string) file_get_contents($file), true);
        foreach (['flex-require' => 'require', 'flex-require-dev' => 'require-dev'] as $from => $to) {
            if (!empty($json[$from])) {
                $json[$to] = ($json[$to] ?? []) + $json[$from];
                unset($json[$from]);
            }
        }
        file_put_contents($file, json_encode($json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $context->report('Résolution des dépendances du projet généré');
        $this->composer(['update', '--no-install', '--ignore-platform-reqs', '--no-audit'], $project, $context);

        return (string) file_get_contents($project.'/composer.lock');
    }

    /** @param list<string> $arguments */
    private function composer(array $arguments, string $cwd, JobContext $context): void
    {
        $env = ['COMPOSER_NO_INTERACTION' => '1'];
        $auth = '' !== $this->githubToken ? ['github-oauth' => ['github.com' => $this->githubToken]] : [];
        if ([] !== $auth = $this->sources?->composerAuth($auth) ?? $auth) {
            $env['COMPOSER_AUTH'] = (string) json_encode($auth, \JSON_UNESCAPED_SLASHES);
        }
        Tool::run(['composer', ...$arguments, '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress'], $cwd, $context, $env);
    }

    /** @return array<string, string> */
    private function npmEnv(): array
    {
        return ['npm_config_registry' => $this->npmRegistry(), 'npm_config_update_notifier' => 'false', 'npm_config_fund' => 'false'];
    }

    /** Registre npm public, ou le dépôt qui le remplace */
    private function npmRegistry(): string
    {
        return $this->sources?->npmRegistry('', $this->registry) ?? $this->registry;
    }
}
