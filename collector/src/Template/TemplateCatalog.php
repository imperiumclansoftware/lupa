<?php

namespace App\Template;

use Sas\Bundle\Ecosystem;

/**
 * Modèles de framework proposés à la création d'une liste.
 *  - « generator » : Rémus exécute le vrai générateur (ng new, create-vite, composer create-project…)
 *    et collecte le fichier de verrouillage obtenu, plus le générateur lui-même : la même commande
 *    fonctionne ensuite hors ligne avec Romulus ;
 *  - « preset » : une liste de paquets prête à l'emploi, recopiée dans le formulaire.
 */
final class TemplateCatalog
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [
            [
                'id' => 'angular', 'ecosystem' => 'npm', 'kind' => 'generator', 'label' => 'Angular',
                'description' => 'Tout ce que « ng new » installe (avec et sans SSR), et la CLI Angular.',
                'version' => '22', 'versionHint' => 'Version majeure de la CLI, ex. 22 ; ou « latest ».',
                'usage' => "npx @angular/cli@{version} new mon-app\n# ou : npm install -g @angular/cli@{version} && ng new mon-app",
            ],
            [
                'id' => 'vite-react', 'ecosystem' => 'npm', 'kind' => 'generator', 'label' => 'React (Vite)',
                'description' => 'Modèle react-ts de create-vite.', 'version' => 'latest', 'versionHint' => 'Version de create-vite.',
                'usage' => 'npm create vite@{version} mon-app -- --template react-ts',
            ],
            [
                'id' => 'vite-vue', 'ecosystem' => 'npm', 'kind' => 'generator', 'label' => 'Vue (Vite)',
                'description' => 'Modèle vue-ts de create-vite.', 'version' => 'latest', 'versionHint' => 'Version de create-vite.',
                'usage' => 'npm create vite@{version} mon-app -- --template vue-ts',
            ],
            [
                'id' => 'vite-svelte', 'ecosystem' => 'npm', 'kind' => 'generator', 'label' => 'Svelte (Vite)',
                'description' => 'Modèle svelte-ts de create-vite.', 'version' => 'latest', 'versionHint' => 'Version de create-vite.',
                'usage' => 'npm create vite@{version} mon-app -- --template svelte-ts',
            ],
            [
                'id' => 'vite-vanilla', 'ecosystem' => 'npm', 'kind' => 'generator', 'label' => 'TypeScript (Vite)',
                'description' => 'Modèle vanilla-ts de create-vite.', 'version' => 'latest', 'versionHint' => 'Version de create-vite.',
                'usage' => 'npm create vite@{version} mon-app -- --template vanilla-ts',
            ],
            [
                'id' => 'nestjs', 'ecosystem' => 'npm', 'kind' => 'generator', 'label' => 'NestJS',
                'description' => 'Tout ce que « nest new » installe, et la CLI Nest.',
                'version' => 'latest', 'versionHint' => 'Version de @nestjs/cli, ex. 11.',
                'usage' => 'npx @nestjs/cli@{version} new mon-app --package-manager npm',
            ],
            [
                'id' => 'symfony', 'ecosystem' => 'composer', 'kind' => 'generator', 'label' => 'Symfony',
                'description' => 'symfony/skeleton et symfony/webapp-pack (application web complète), dépendances de dev comprises.',
                'version' => '8.1', 'versionHint' => 'Version de Symfony, ex. 7.3 ou 8.1.',
                'usage' => "composer create-project symfony/skeleton:\"{version}.*\" mon-app\ncd mon-app && composer require webapp",
            ],
            [
                'id' => 'laravel', 'ecosystem' => 'composer', 'kind' => 'generator', 'label' => 'Laravel',
                'description' => 'Squelette laravel/laravel, dépendances de dev comprises.',
                'version' => '12', 'versionHint' => 'Version majeure de laravel/laravel, ex. 12.',
                'usage' => 'composer create-project laravel/laravel:^{version} mon-app',
            ],
            [
                'id' => 'django', 'ecosystem' => 'pypi', 'kind' => 'preset', 'label' => 'Django',
                'description' => 'Django et les outils d\'un projet courant.',
                'specs' => "django\npsycopg[binary]\ngunicorn\npython-dotenv",
            ],
            [
                'id' => 'fastapi', 'ecosystem' => 'pypi', 'kind' => 'preset', 'label' => 'FastAPI',
                'description' => 'FastAPI avec ses extras standard (uvicorn, CLI…).',
                'specs' => "fastapi[standard]\nsqlalchemy\npydantic-settings",
            ],
            [
                'id' => 'flask', 'ecosystem' => 'pypi', 'kind' => 'preset', 'label' => 'Flask',
                'description' => 'Flask et un serveur de production.',
                'specs' => "flask\ngunicorn\npython-dotenv",
            ],
            [
                'id' => 'data', 'ecosystem' => 'pypi', 'kind' => 'preset', 'label' => 'Data science',
                'description' => 'Calcul, tableaux, graphiques et JupyterLab.',
                'specs' => "numpy\npandas\nmatplotlib\nscikit-learn\njupyterlab",
            ],
            [
                'id' => 'python-tools', 'ecosystem' => 'pypi', 'kind' => 'preset', 'label' => 'Outils Python',
                'description' => 'Indispensables pour créer des environnements et installer depuis les sources.',
                'specs' => "pip\nsetuptools\nwheel\nvirtualenv\nuv\npytest\nruff",
            ],
            [
                'id' => 'docker-web', 'ecosystem' => 'docker', 'kind' => 'preset', 'label' => 'Images web',
                'description' => 'Images de base courantes pour des applications web.',
                'specs' => "node:24-alpine\nphp:8.4-fpm-alpine\npython:3.12-slim\nnginx:alpine\ncaddy:2-alpine",
            ],
            [
                'id' => 'docker-data', 'ecosystem' => 'docker', 'kind' => 'preset', 'label' => 'Bases de données',
                'description' => 'Bases de données et caches.',
                'specs' => "postgres:17-alpine\nmariadb:11\nredis:7-alpine\nmongo:8",
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $template) {
            if ($template['id'] === $id) {
                return $template;
            }
        }

        return null;
    }

    /** @return list<string> erreurs lisibles */
    public static function validate(Ecosystem $ecosystem, mixed $option): array
    {
        if (null === $option) {
            return [];
        }
        $template = \is_array($option) ? self::find((string) ($option['id'] ?? '')) : null;
        if (!$template || 'generator' !== $template['kind'] || $template['ecosystem'] !== $ecosystem->value) {
            return ['Modèle de framework inconnu pour cet écosystème.'];
        }
        if (!preg_match('/^(latest|\d+(\.\d+){0,2})$/', (string) ($option['version'] ?? ''))) {
            return [sprintf('Version du modèle %s invalide : %s', $template['label'], $template['versionHint'])];
        }

        return [];
    }
}
