<?php

namespace App\Resolver;

use App\Blacklist\Blacklist;
use App\Entity\Artifact;
use App\Entity\PackageList;
use App\Http\Downloader;
use App\Job\JobContext;
use Sas\Bundle\Ecosystem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Ansible Galaxy :
 *  - collections (« community.general >=8 ») : version choisie via l'API v3, dépendances suivies,
 *    archive vérifiée par son sha256 ;
 *  - rôles (« role:geerlingguy.docker 7.4.1 ») : archive GitHub de la version publiée sur Galaxy.
 * Un requirements.yml importé est accepté (collections et rôles).
 */
final readonly class GalaxyResolver implements Resolver
{
    private const string NAME = '/^(role:)?([a-z0-9_]+)\.([a-z0-9_-]+)$/i';

    public function __construct(
        private Downloader $downloader,
        private ArtifactCatalog $catalog,
        private Blacklist $blacklist,
        #[Autowire('%env(GALAXY_URL)%')]
        private string $galaxy,
    ) {
    }

    public function ecosystem(): Ecosystem
    {
        return Ecosystem::Galaxy;
    }

    /** @return array{string, string, string} type (collection|role), nom (ns.nom), contrainte */
    public static function parseSpec(string $line): array
    {
        $parts = preg_split('/[\s:](?=[<>=!~*\d])|\s+/', trim($line), 2) ?: [$line];
        $name = $parts[0];
        $constraint = trim($parts[1] ?? '') ?: '*';
        $role = str_starts_with(strtolower($name), 'role:');

        return [$role ? 'role' : 'collection', strtolower($role ? substr($name, 5) : $name), $constraint];
    }

    public function validate(PackageList $list): array
    {
        $errors = [];
        foreach ($this->requirements($list) as [$type, $name, $constraint]) {
            if (!preg_match(self::NAME, ('role' === $type ? 'role:' : '').$name)) {
                $errors[] = sprintf('« %s » : attendu espace.nom (collection, ex. community.general >=8.0.0) ou role:auteur.nom (rôle, ex. role:geerlingguy.docker 7.4.1).', $name);
            } elseif ('collection' === $type && !Constraint::isValid($constraint)) {
                $errors[] = sprintf('« %s %s » : contrainte de version invalide (ex. >=2.0.0,<3.0.0).', $name, $constraint);
            }
        }
        if (null !== $list->getLockfile()) {
            try {
                self::parseRequirementsFile($list->getLockfile());
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ([] === $this->requirements($list)) {
            $errors[] = 'La liste est vide : saisissez des collections (community.general) ou importez un requirements.yml.';
        }

        return $errors;
    }

    public function resolve(PackageList $list, JobContext $context): array
    {
        $collections = [];
        $roles = [];
        foreach ($this->requirements($list) as [$type, $name, $constraint]) {
            if ('role' === $type) {
                $roles[$name] = $constraint;
            } else {
                $collections[] = [$name, $constraint, null];
            }
        }

        // Collections : file d'attente, chaque collection retenue une fois (la première contrainte l'emporte)
        $selected = [];
        $artifacts = [];
        while (null !== $item = array_shift($collections)) {
            [$name, $constraint, $by] = $item;
            if (isset($selected[$name])) {
                if ('*' !== Constraint::normalize($constraint) && null === Constraint::best([$selected[$name]], $constraint)) {
                    $context->log(sprintf('ATTENTION %s %s retenue, mais %s demande %s.', $name, $selected[$name], $by ?? 'la liste', $constraint));
                }
                continue;
            }
            [$namespace, $collection] = explode('.', $name, 2);
            $context->report(sprintf('Collection %s', $name));
            $versions = $this->collectionVersions($namespace, $collection);
            if ([] === $versions) {
                throw new \RuntimeException(sprintf('Collection introuvable sur Galaxy : %s.', $name));
            }
            $version = Constraint::best($versions, $constraint) ?? throw new \RuntimeException(sprintf('Aucune version de %s ne respecte « %s »%s.', $name, $constraint, $by ? ' (demandée par '.$by.')' : ''));
            $selected[$name] = $version;
            if ($this->blacklist->blocks(Ecosystem::Galaxy, $name, $version, $context)) {
                continue;
            }
            [$artifact, $dependencies] = $this->collection($namespace, $collection, $version, $context);
            $artifacts[] = $artifact;
            foreach ($dependencies as $dependency => $wanted) {
                $collections[] = [strtolower((string) $dependency), (string) $wanted, $name];
            }
        }
        $context->log(sprintf('%d collection(s), dépendances comprises.', \count($selected)));

        foreach ($roles as $name => $constraint) {
            $context->report(sprintf('Rôle %s', $name));
            $artifact = $this->role($name, $constraint, $context);
            if ($artifact) {
                $artifacts[] = $artifact;
            }
        }
        $this->catalog->flush();

        return $artifacts;
    }

    /** @return list<string> */
    private function collectionVersions(string $namespace, string $name): array
    {
        $versions = [];
        $url = sprintf('%s/api/v3/plugin/ansible/content/published/collections/index/%s/%s/versions/?limit=100', $this->galaxy, $namespace, $name);
        while (null !== $url) {
            try {
                $data = $this->downloader->json($url);
            } catch (\App\Http\DownloadException $e) {
                if (404 === $e->getCode()) {
                    return [];
                }
                throw $e;
            }
            foreach ($data['data'] ?? [] as $item) {
                $versions[] = (string) $item['version'];
            }
            $next = $data['links']['next'] ?? null;
            $url = \is_string($next) ? (str_starts_with($next, 'http') ? $next : $this->galaxy.$next) : null;
        }

        return $versions;
    }

    /** @return array{Artifact, array<string, string>} */
    private function collection(string $namespace, string $name, string $version, JobContext $context): array
    {
        $full = $namespace.'.'.$name;
        $existing = $this->catalog->complete(Ecosystem::Galaxy, $full, $version, null);
        if ($existing) {
            return [$existing, $existing->getMetadata()['dependencies'] ?? []];
        }
        $data = $this->downloader->json(sprintf('%s/api/v3/plugin/ansible/content/published/collections/index/%s/%s/versions/%s/', $this->galaxy, $namespace, $name, rawurlencode($version)));
        $artifact = $data['artifact'] ?? [];
        if (!\is_string($data['download_url'] ?? null) || !\is_string($artifact['sha256'] ?? null)) {
            throw new \RuntimeException(sprintf('Archive introuvable pour %s %s.', $full, $version));
        }
        $context->report(sprintf('Téléchargement %s %s', $full, $version));
        $blob = $this->downloader->fetch($data['download_url'], sha256: strtolower($artifact['sha256']));
        $dependencies = (array) ($data['metadata']['dependencies'] ?? []);

        return [$this->catalog->record(Ecosystem::Galaxy, $full, $version, null, [$blob], [
            'type' => 'collection',
            'namespace' => $namespace,
            'name' => $name,
            'filename' => (string) ($artifact['filename'] ?? sprintf('%s-%s-%s.tar.gz', $namespace, $name, $version)),
            'dependencies' => (object) $dependencies,
            'requiresAnsible' => $data['requires_ansible'] ?? null,
            'description' => $data['metadata']['description'] ?? null,
        ]), $dependencies];
    }

    private function role(string $name, string $constraint, JobContext $context): ?Artifact
    {
        [$owner, $role] = explode('.', $name, 2);
        $data = $this->downloader->json(sprintf('%s/api/v1/roles/?github_user=%s&name=%s', $this->galaxy, rawurlencode($owner), rawurlencode($role)));
        $found = $data['results'][0] ?? null;
        if (!\is_array($found)) {
            // Espace de noms Galaxy différent du compte GitHub
            $data = $this->downloader->json(sprintf('%s/api/v1/roles/?namespace=%s&name=%s', $this->galaxy, rawurlencode($owner), rawurlencode($role)));
            $found = $data['results'][0] ?? null;
        }
        if (!\is_array($found)) {
            throw new \RuntimeException(sprintf('Rôle introuvable sur Galaxy : %s.', $name));
        }
        $versions = array_map(static fn (array $v) => (string) $v['name'], $found['summary_fields']['versions'] ?? []);
        $version = [] === $versions ? null : Constraint::best($versions, $constraint);
        if (null === $version && [] !== $versions && isset(array_flip($versions)[$constraint])) {
            $version = $constraint;
        }
        if (null === $version) {
            throw new \RuntimeException(sprintf('Aucune version du rôle %s ne respecte « %s » (versions : %s).', $name, $constraint, implode(', ', \array_slice($versions, 0, 10)) ?: 'aucune'));
        }
        if ($this->blacklist->blocks(Ecosystem::Galaxy, $name, $version, $context)) {
            return null;
        }
        $filename = sprintf('roles/%s-%s.tar.gz', $name, $version);
        if ($existing = $this->catalog->complete(Ecosystem::Galaxy, $name, $version, $filename)) {
            return $existing;
        }
        // Comme ansible-galaxy : archive GitHub de l'étiquette (aucune somme publiée, transport HTTPS)
        $url = sprintf('https://github.com/%s/%s/archive/%s.tar.gz', $found['github_user'], $found['github_repo'], rawurlencode($version));
        $context->report(sprintf('Téléchargement du rôle %s %s', $name, $version));
        $blob = $this->downloader->fetch($url);
        $context->log(sprintf('Rôle %s %s (les dépendances d\'un rôle, dans meta/main.yml, ne sont pas suivies).', $name, $version));

        return $this->catalog->record(Ecosystem::Galaxy, $name, $version, $filename, [$blob], [
            'type' => 'role',
            'namespace' => $owner,
            'name' => $role,
            'filename' => basename($filename),
            'source' => sprintf('https://github.com/%s/%s', $found['github_user'], $found['github_repo']),
            'description' => $found['description'] ?? null,
        ]);
    }

    /** @return list<array{string, string, string}> */
    private function requirements(PackageList $list): array
    {
        $items = array_map(self::parseSpec(...), $list->getSpecLines());
        if (null !== $list->getLockfile()) {
            try {
                array_push($items, ...self::parseRequirementsFile($list->getLockfile()));
            } catch (\InvalidArgumentException) {
            }
        }

        return $items;
    }

    /**
     * requirements.yml d'ansible-galaxy : « collections: » et « roles: », entrées texte ou {name, version, src}.
     *
     * @return list<array{string, string, string}>
     */
    public static function parseRequirementsFile(string $yaml): array
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('requirements.yml illisible : '.$e->getMessage());
        }
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('requirements.yml vide ou invalide.');
        }
        // Ancien format : liste de rôles à la racine
        if (array_is_list($data)) {
            $data = ['roles' => $data];
        }
        $items = [];
        foreach (['collections' => 'collection', 'roles' => 'role'] as $key => $type) {
            foreach ((array) ($data[$key] ?? []) as $entry) {
                $name = \is_array($entry) ? (string) ($entry['name'] ?? $entry['src'] ?? '') : (string) $entry;
                $version = \is_array($entry) ? (string) ($entry['version'] ?? '*') : '*';
                if ('' === $name || str_contains($name, '/') || str_contains($name, ':')) {
                    continue; // source git ou URL : hors Galaxy
                }
                $items[] = [$type, strtolower($name), '' === $version ? '*' : $version];
            }
        }

        return $items;
    }
}
