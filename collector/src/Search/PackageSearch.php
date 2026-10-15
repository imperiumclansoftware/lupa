<?php

namespace App\Search;

use App\Http\DownloadException;
use App\Resolver\Docker\ImageReference;
use App\Resolver\Docker\RegistryClient;
use App\Resolver\PypiResolver;
use App\Source\Sources;
use Sas\Bundle\Ecosystem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Recherche dans les registres publics (Rémus est connecté) : paquets par nom, versions disponibles,
 * et liste des noms correspondant à un motif (« * », « ? »).
 *
 * @phpstan-type Result array{name: string, description: string|null, version: string|null, info: string|null}
 */
final class PackageSearch
{
    /** Au-delà, un motif est jugé trop large */
    public const int MAX_MATCHES = 300;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly RegistryClient $registry,
        #[Autowire('%env(NPM_REGISTRY)%')]
        private readonly string $npmRegistry,
        #[Autowire('%env(PYPI_INDEX)%')]
        private readonly string $pypiIndex,
        #[Autowire('%kernel.cache_dir%')]
        private readonly string $cacheDir,
        private readonly ?Sources $sources = null,
        private readonly ?\App\System\SystemSearch $system = null,
    ) {
    }

    /** @return list<Result> */
    public function search(Ecosystem $ecosystem, string $query, int $limit = 20, ?string $distribution = null): array
    {
        $query = trim($query);
        if ('' === $query) {
            return [];
        }

        return match ($ecosystem) {
            Ecosystem::Npm => $this->npmSearch($query, $limit),
            Ecosystem::Pypi => $this->pypiSearch($query, $limit),
            Ecosystem::Composer => $this->composerSearch($query, $limit),
            Ecosystem::Docker => $this->dockerSearch($query, $limit),
            Ecosystem::Deb, Ecosystem::Rpm => $this->system->search($ecosystem, $distribution, $query, $limit),
            Ecosystem::Galaxy => $this->galaxySearch($query, $limit),
            Ecosystem::Terraform => $this->terraformSearch($query, $limit),
        };
    }

    /**
     * Versions publiées, de la plus récente à la plus ancienne (200 au plus).
     *
     * @return array{versions: list<string>, latest: string|null}
     */
    public function versions(Ecosystem $ecosystem, string $name, ?string $distribution = null): array
    {
        $versions = match ($ecosystem) {
            Ecosystem::Npm => $this->npmVersions($name),
            Ecosystem::Pypi => $this->pypiVersions($name),
            Ecosystem::Composer => $this->composerVersions($name),
            Ecosystem::Docker => $this->dockerTags($name),
            Ecosystem::Deb, Ecosystem::Rpm => $this->system->versions($ecosystem, $distribution, $name),
            Ecosystem::Galaxy => $this->galaxyVersions($name),
            Ecosystem::Terraform => $this->terraformVersions($name),
        };

        return ['versions' => \array_slice($versions['versions'], 0, 200), 'latest' => $versions['latest']];
    }

    /**
     * Noms de paquets (ou de dépôts Docker Hub) correspondant à un motif.
     *
     * @return list<string>
     */
    public function names(Ecosystem $ecosystem, string $pattern, ?string $distribution = null): array
    {
        $names = match ($ecosystem) {
            Ecosystem::Npm => $this->npmNames($pattern),
            Ecosystem::Pypi => array_values(array_filter($this->pypiIndex(), static fn (string $n) => Glob::matches(self::pep503($pattern), self::pep503($n)))),
            Ecosystem::Composer => $this->composerNames($pattern),
            Ecosystem::Docker => $this->dockerRepositories($pattern),
            Ecosystem::Deb, Ecosystem::Rpm => $this->system->names($ecosystem, $distribution, $pattern),
            Ecosystem::Galaxy, Ecosystem::Terraform => throw new \InvalidArgumentException('Les motifs ne sont pas pris en charge pour cet écosystème.'),
        };
        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * Versions publiées de plusieurs paquets, en parallèle (pour vérifier une contrainte sur un motif).
     *
     * @param list<string> $names
     *
     * @return array<string, list<string>>
     */
    public function versionsMany(Ecosystem $ecosystem, array $names): array
    {
        $result = [];
        foreach (array_chunk($names, 16) as $chunk) {
            $responses = [];
            foreach ($chunk as $name) {
                [$url, $headers] = match ($ecosystem) {
                    Ecosystem::Npm => [sprintf('%s/%s', $this->npmRegistry($name), $name), ['Accept' => 'application/vnd.npm.install-v1+json']],
                    Ecosystem::Composer => [sprintf('https://repo.packagist.org/p2/%s.json', strtolower($name)), []],
                    Ecosystem::Pypi => [sprintf('%s/pypi/%s/json', rtrim($this->pypiIndex, '/'), rawurlencode($name)), []],
                    default => throw new \LogicException('Sans objet pour cet écosystème.'),
                };
                $responses[$name] = $this->http->request('GET', $url, ($this->sources?->httpOptions($url) ?? []) + ['headers' => $headers, 'timeout' => 60]);
            }
            foreach ($responses as $name => $response) {
                try {
                    $data = 200 === $response->getStatusCode() ? $response->toArray() : [];
                } catch (\Throwable) {
                    $data = [];
                }
                $result[$name] = array_values(array_map('strval', match ($ecosystem) {
                    Ecosystem::Npm => array_keys($data['versions'] ?? []),
                    Ecosystem::Composer => array_column($data['packages'][strtolower($name)] ?? [], 'version'),
                    Ecosystem::Pypi => array_keys(array_filter($data['releases'] ?? [], static fn ($files) => [] !== array_filter((array) $files, static fn ($f) => !($f['yanked'] ?? false)))),
                    default => [],
                }));
            }
        }

        return $result;
    }

    /**
     * Étiquettes d'une image correspondant à un motif (node:24-*alpine).
     *
     * @return list<string>
     */
    public function tags(string $image, string $pattern): array
    {
        // API du registre (toutes les étiquettes, 1000 par requête) : plus adaptée qu'une API paginée par 100
        $tags = $this->registry->tags(ImageReference::parse($image), 20000);

        return array_values(array_filter($tags, static fn (string $t) => !str_starts_with($t, 'sha256-') && Glob::matches($pattern, $t)));
    }

    // ---------- npm ----------

    /** @return list<Result> */
    private function npmSearch(string $query, int $limit): array
    {
        $data = $this->json(sprintf('%s/-/v1/search', rtrim($this->npmRegistry, '/')), ['text' => $query, 'size' => $limit]);
        $results = [];
        foreach ($data['objects'] ?? [] as $object) {
            $p = $object['package'] ?? [];
            $results[] = self::result((string) $p['name'], $p['description'] ?? null, $p['version'] ?? null, null);
        }

        return $results;
    }

    /** @return array{versions: list<string>, latest: string|null} */
    private function npmVersions(string $name): array
    {
        $data = $this->json(sprintf('%s/%s', $this->npmRegistry($name), $name), [], ['Accept' => 'application/vnd.npm.install-v1+json']);
        $versions = array_map('strval', array_keys($data['versions'] ?? []));
        usort($versions, static fn (string $a, string $b) => self::compareVersions($b, $a));

        return ['versions' => $versions, 'latest' => $data['dist-tags']['latest'] ?? ($versions[0] ?? null)];
    }

    /** Collections (nom exact, puis mots-clés) et rôles Galaxy. */
    private function galaxySearch(string $query, int $limit): array
    {
        $base = 'https://galaxy.ansible.com';
        $results = [];
        foreach ([['name' => strtolower($query)], ['keywords' => $query]] as $filter) {
            $data = $this->json($base.'/api/v3/plugin/ansible/search/collection-versions/', $filter + ['is_highest' => 'true', 'limit' => $limit]);
            foreach ($data['data'] ?? [] as $item) {
                $c = $item['collection_version'] ?? [];
                $name = ($c['namespace'] ?? '').'.'.($c['name'] ?? '');
                $results[$name] ??= self::result($name, $c['description'] ?? null, $c['version'] ?? null, 'Collection');
            }
        }
        // Collections officielles et communautaires d'abord (community.docker avant uumas.docker)
        uksort($results, static fn (string $a, string $b) => (int) !preg_match('/^(ansible|community)\./', $a) <=> (int) !preg_match('/^(ansible|community)\./', $b));
        $results = \array_slice($results, 0, max(1, $limit - 5), true);
        $roles = $this->json($base.'/api/v1/roles/', ['keywords' => $query, 'page_size' => 5, 'order_by' => '-download_count']);
        foreach ($roles['results'] ?? [] as $role) {
            $name = 'role:'.($role['summary_fields']['namespace']['name'] ?? $role['github_user']).'.'.$role['name'];
            $results[$name] ??= self::result($name, $role['description'] ?? null, $role['summary_fields']['versions'][0]['name'] ?? null, 'Rôle');
        }

        return \array_slice(array_values($results), 0, $limit);
    }

    /** @return array{versions: list<string>, latest: string|null} */
    private function galaxyVersions(string $name): array
    {
        $base = 'https://galaxy.ansible.com';
        if (str_starts_with($name, 'role:')) {
            [$owner, $role] = explode('.', substr($name, 5), 2) + [1 => ''];
            $data = $this->json($base.'/api/v1/roles/', ['namespace' => $owner, 'name' => $role]);
            $versions = array_map(static fn (array $v) => (string) $v['name'], $data['results'][0]['summary_fields']['versions'] ?? []);
        } else {
            [$namespace, $collection] = explode('.', $name, 2) + [1 => ''];
            $data = $this->json(sprintf('%s/api/v3/plugin/ansible/content/published/collections/index/%s/%s/versions/', $base, $namespace, $collection), ['limit' => 100]);
            $versions = array_map(static fn (array $v) => (string) $v['version'], $data['data'] ?? []);
        }
        usort($versions, static fn (string $a, string $b) => self::compareVersions($b, $a));

        return ['versions' => $versions, 'latest' => array_values(array_filter($versions, static fn ($v) => !str_contains($v, '-')))[0] ?? ($versions[0] ?? null)];
    }

    /** Providers (officiels et partenaires d'abord) et modules du registre Terraform. */
    private function terraformSearch(string $query, int $limit): array
    {
        $base = 'https://registry.terraform.io';
        $query = strtolower(trim($query));
        $results = [];
        if ('terraform' === $query) {
            $results['terraform'] = self::result('terraform', 'Le binaire Terraform (CLI)', null, 'CLI');
        }
        $providers = $this->json($base.'/v2/providers', ['filter[name]' => $query, 'page[size]' => 50]);
        $tiers = ['official' => 0, 'partner' => 1];
        $items = $providers['data'] ?? [];
        usort($items, static fn (array $a, array $b) => ($tiers[$a['attributes']['tier'] ?? ''] ?? 2) <=> ($tiers[$b['attributes']['tier'] ?? ''] ?? 2)
            ?: ($b['attributes']['downloads'] ?? 0) <=> ($a['attributes']['downloads'] ?? 0));
        foreach (\array_slice($items, 0, 8) as $item) {
            $name = strtolower((string) $item['attributes']['full-name']);
            $results[$name] = self::result($name, $item['attributes']['description'] ?? null, null, 'Provider '.($item['attributes']['tier'] ?? ''));
        }
        $modules = $this->json($base.'/v1/modules/search', ['q' => $query, 'limit' => $limit]);
        foreach ($modules['modules'] ?? [] as $module) {
            $name = sprintf('%s/%s/%s', $module['namespace'], $module['name'], $module['provider']);
            $results[$name] ??= self::result($name, $module['description'] ?? null, $module['version'] ?? null, 'Module');
        }

        return \array_slice(array_values($results), 0, $limit);
    }

    /** @return array{versions: list<string>, latest: string|null} */
    private function terraformVersions(string $name): array
    {
        $segments = explode('/', strtolower($name));
        if ('terraform' === $name) {
            $versions = array_keys($this->json('https://releases.hashicorp.com/terraform/index.json')['versions'] ?? []);
        } elseif (3 === \count($segments)) {
            $data = $this->json('https://registry.terraform.io/v1/modules/'.$name.'/versions');
            $versions = array_map(static fn (array $v) => (string) $v['version'], $data['modules'][0]['versions'] ?? []);
        } else {
            $data = $this->json('https://registry.terraform.io/v1/providers/'.$name.'/versions');
            $versions = array_map(static fn (array $v) => (string) $v['version'], $data['versions'] ?? []);
        }
        $versions = array_map('strval', $versions);
        usort($versions, static fn (string $a, string $b) => self::compareVersions($b, $a));

        return ['versions' => $versions, 'latest' => array_values(array_filter($versions, static fn ($v) => !str_contains($v, '-')))[0] ?? ($versions[0] ?? null)];
    }

    /** @return list<string> */
    private function npmNames(string $pattern): array
    {
        // Paquet d'une portée (@syncfusion/…) : liste exacte des paquets de l'organisation
        if (preg_match('#^@([a-z0-9][a-z0-9._~-]*)/#i', $pattern, $m) && !Glob::isPattern($m[1])) {
            try {
                $packages = $this->json(sprintf('%s/-/org/%s/package', rtrim($this->npmRegistry, '/'), strtolower($m[1])));

                return array_values(array_filter(array_map('strval', array_keys($packages)), static fn (string $n) => Glob::matches($pattern, $n)));
            } catch (DownloadException) {
                // Portée d'un utilisateur (pas une organisation) : recherche ci-dessous
            }
            $text = 'scope:'.$m[1].' '.(Glob::literals(substr($pattern, \strlen($m[0])))[0] ?? '');
        } else {
            $text = Glob::literals($pattern)[0] ?? '';
        }
        if ('' === trim($text)) {
            throw new \InvalidArgumentException(sprintf('Motif « %s » trop large : ajoutez une portée ou un fragment de nom.', $pattern));
        }

        // Résultats par pertinence : on s'arrête quand une page n'apporte plus aucune correspondance
        $names = [];
        for ($from = 0; $from < 2500; $from += 250) {
            $data = $this->json(sprintf('%s/-/v1/search', rtrim($this->npmRegistry, '/')), ['text' => trim($text), 'size' => 250, 'from' => $from]);
            $objects = $data['objects'] ?? [];
            $found = 0;
            foreach ($objects as $object) {
                $name = (string) ($object['package']['name'] ?? '');
                if (Glob::matches($pattern, $name)) {
                    $names[] = $name;
                    ++$found;
                }
            }
            if (\count($objects) < 250 || (0 === $found && [] !== $names)) {
                break;
            }
        }

        return $names;
    }

    // ---------- PyPI ----------

    /** @return list<Result> */
    private function pypiSearch(string $query, int $limit): array
    {
        $needle = self::pep503($query);
        $exact = $prefix = $contains = [];
        foreach ($this->pypiIndex() as $name) {
            $normalized = self::pep503($name);
            if ($normalized === $needle) {
                $exact[] = $name;
            } elseif (str_starts_with($normalized, $needle)) {
                $prefix[] = $name;
            } elseif (str_contains($normalized, $needle)) {
                $contains[] = $name;
            }
        }
        usort($prefix, static fn ($a, $b) => \strlen($a) <=> \strlen($b));
        usort($contains, static fn ($a, $b) => \strlen($a) <=> \strlen($b));
        $names = \array_slice([...$exact, ...$prefix, ...$contains], 0, $limit);

        // Résumé et dernière version des premiers résultats, en parallèle
        $responses = [];
        foreach (\array_slice($names, 0, 12) as $name) {
            $responses[$name] = $this->http->request('GET', sprintf('%s/pypi/%s/json', rtrim($this->pypiIndex, '/'), rawurlencode($name)), ['timeout' => 15]);
        }
        $results = [];
        foreach ($names as $name) {
            $info = [];
            try {
                if (isset($responses[$name]) && 200 === $responses[$name]->getStatusCode()) {
                    $info = $responses[$name]->toArray()['info'] ?? [];
                }
            } catch (\Throwable) {
            }
            $results[] = self::result($name, $info['summary'] ?? null, $info['version'] ?? null, null);
        }

        return $results;
    }

    /** @return array{versions: list<string>, latest: string|null} */
    private function pypiVersions(string $name): array
    {
        $data = $this->json(sprintf('%s/pypi/%s/json', rtrim($this->pypiIndex, '/'), rawurlencode($name)));
        $versions = [];
        foreach ($data['releases'] ?? [] as $version => $files) {
            $live = array_filter((array) $files, static fn ($f) => !($f['yanked'] ?? false));
            if ([] !== $live) {
                $versions[] = (string) $version;
            }
        }
        usort($versions, static fn (string $a, string $b) => self::compareVersions($b, $a));

        return ['versions' => $versions, 'latest' => $data['info']['version'] ?? ($versions[0] ?? null)];
    }

    /**
     * Noms de tous les projets PyPI (index « simple », 45 Mo), téléchargé au plus une fois par jour.
     *
     * @return list<string>
     */
    private function pypiIndex(): array
    {
        $file = $this->cacheDir.'/pypi-projects.txt';
        if (!is_file($file) || filemtime($file) < time() - 86400) {
            $response = $this->http->request('GET', rtrim($this->pypiIndex, '/').'/simple/', [
                'headers' => ['Accept' => 'application/vnd.pypi.simple.v1+json'],
                'timeout' => 120,
            ]);
            if (200 !== $response->getStatusCode()) {
                throw new DownloadException('Index PyPI indisponible.');
            }
            preg_match_all('/"name"\s*:\s*"([^"]+)"/', $response->getContent(), $m);
            file_put_contents($file.'.tmp', implode("\n", $m[1]));
            rename($file.'.tmp', $file);
        }

        return explode("\n", (string) file_get_contents($file));
    }

    // ---------- Composer ----------

    /** @return list<Result> */
    private function composerSearch(string $query, int $limit): array
    {
        $data = $this->json('https://packagist.org/search.json', ['q' => $query, 'per_page' => $limit]);
        $results = [];
        foreach ($data['results'] ?? [] as $r) {
            $results[] = self::result((string) $r['name'], $r['description'] ?? null, null, isset($r['downloads']) ? self::count((int) $r['downloads']).' téléchargements' : null);
        }

        return $results;
    }

    /** @return array{versions: list<string>, latest: string|null} */
    private function composerVersions(string $name): array
    {
        $data = $this->json(sprintf('https://repo.packagist.org/p2/%s.json', strtolower($name)));
        $versions = array_values(array_unique(array_map(static fn ($v) => (string) ($v['version'] ?? ''), $data['packages'][strtolower($name)] ?? [])));
        $versions = array_values(array_filter($versions, static fn ($v) => '' !== $v));
        usort($versions, static fn (string $a, string $b) => self::compareVersions($b, $a));
        $stable = array_values(array_filter($versions, static fn ($v) => !preg_match('/-(alpha|beta|rc|dev)/i', $v)));

        return ['versions' => $versions, 'latest' => $stable[0] ?? ($versions[0] ?? null)];
    }

    /** @return list<string> */
    private function composerNames(string $pattern): array
    {
        $data = $this->json('https://packagist.org/packages/list.json', ['filter' => str_replace('?', '*', $pattern)]);

        return array_values(array_filter(array_map('strval', $data['packageNames'] ?? []), static fn (string $n) => Glob::matches($pattern, $n)));
    }

    // ---------- Docker ----------

    /** @return list<Result> */
    private function dockerSearch(string $query, int $limit): array
    {
        $data = $this->json('https://hub.docker.com/v2/search/repositories/', ['query' => $query, 'page_size' => $limit]);
        $results = [];
        foreach ($data['results'] ?? [] as $r) {
            $info = ($r['is_official'] ?? false) ? 'Image officielle' : null;
            if (isset($r['star_count'])) {
                $info = trim(($info ? $info.' · ' : '').self::count((int) $r['star_count']).' étoiles');
            }
            $results[] = self::result((string) $r['repo_name'], $r['short_description'] ?? null, null, $info);
        }

        return $results;
    }

    /**
     * @param string|null $contains fragment que l'étiquette doit contenir (filtre côté Docker Hub)
     *
     * @return array{versions: list<string>, latest: string|null}
     */
    private function dockerTags(string $name, int $max = 300, ?string $contains = null): array
    {
        $image = ImageReference::parse($name);
        $tags = [];
        if ('docker.io' === $image->registry) {
            // API Docker Hub : étiquettes les plus récentes d'abord, filtrées par nom (évite les limites de débit)
            $url = sprintf('https://hub.docker.com/v2/repositories/%s/tags', $image->repository);
            $query = array_filter(['page_size' => 100, 'ordering' => 'last_updated', 'name' => $contains]);
            $pages = 0;
            while ($url && \count($tags) < $max && $pages++ < 20) {
                if ($pages > 1) {
                    usleep(250_000); // Docker Hub bloque les rafales de requêtes
                }
                $data = $this->json($url, $query);
                foreach ($data['results'] ?? [] as $t) {
                    $tags[] = (string) $t['name'];
                }
                $url = $data['next'] ?? null;
                $query = [];
            }
        } else {
            $tags = $this->registry->tags($image, 20000);
            usort($tags, static fn (string $a, string $b) => self::compareVersions($b, $a));
        }
        // Signatures et attestations cosign (sha256-….sig) : pas des images à collecter
        $tags = array_values(array_filter($tags, static fn (string $t) => !str_starts_with($t, 'sha256-')));

        return ['versions' => \array_slice($tags, 0, $max), 'latest' => \in_array('latest', $tags, true) ? 'latest' : ($tags[0] ?? null)];
    }

    /**
     * Dépôts d'un espace Docker Hub correspondant à un motif (bitnami/*-exporter).
     *
     * @return list<string>
     */
    private function dockerRepositories(string $pattern): array
    {
        if (!preg_match('#^([a-z0-9][a-z0-9_.-]*)/(.+)$#', $pattern, $m) || Glob::isPattern($m[1])) {
            throw new \InvalidArgumentException(sprintf('Motif d\'image « %s » : seul le nom après l\'espace Docker Hub peut contenir « * » (ex. bitnami/*-exporter).', $pattern));
        }
        $names = [];
        $url = sprintf('https://hub.docker.com/v2/namespaces/%s/repositories', $m[1]);
        $query = array_filter(['page_size' => 100, 'name' => Glob::filter($m[2])]);
        $pages = 0;
        while ($url && $pages++ < 30) {
            $data = $this->json($url, $query);
            foreach ($data['results'] ?? [] as $r) {
                $name = $m[1].'/'.$r['name'];
                if (Glob::matches($pattern, $name)) {
                    $names[] = $name;
                }
            }
            $url = $data['next'] ?? null;
            $query = [];
        }

        return $names;
    }

    // ---------- Outils ----------

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     *
     * @return array<mixed>
     */
    /** Registre npm d'un paquet : celui de sa portée privée, ou le public */
    private function npmRegistry(string $name): string
    {
        return $this->sources?->npmRegistry($name, $this->npmRegistry) ?? rtrim($this->npmRegistry, '/');
    }

    private function json(string $url, array $query = [], array $headers = []): array
    {
        $auth = $this->sources?->httpOptions($url) ?? [];
        try {
            $response = $this->http->request('GET', $url, $auth + ['query' => $query, 'headers' => $headers + ['Accept' => 'application/json'], 'timeout' => 30]);
            $status = $response->getStatusCode();
            if (429 === $status) {
                sleep(min(10, max(2, (int) ($response->getHeaders(false)['retry-after'][0] ?? 3))));
                $response = $this->http->request('GET', $url, $auth + ['query' => $query, 'headers' => $headers + ['Accept' => 'application/json'], 'timeout' => 30]);
                $status = $response->getStatusCode();
            }
            if (429 === $status) {
                throw new DownloadException('Le registre limite le nombre de requêtes : réessayez dans une minute.', 429);
            }
            if (404 === $status) {
                throw new DownloadException('Introuvable dans le registre.', 404);
            }
            if (200 !== $status) {
                throw new DownloadException(sprintf('Registre indisponible (HTTP %d).', $status), $status);
            }

            return $response->toArray();
        } catch (DownloadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DownloadException('Registre injoignable : '.$e->getMessage(), previous: $e);
        }
    }

    /** @return Result */
    private static function result(string $name, mixed $description, mixed $version, ?string $info): array
    {
        return [
            'name' => $name,
            'description' => \is_string($description) && '' !== trim($description) ? mb_substr(trim($description), 0, 200) : null,
            'version' => \is_string($version) ? $version : null,
            'info' => $info,
        ];
    }

    public static function pep503(string $name): string
    {
        return PypiResolver::normalize($name);
    }

    /** Comparaison tolérante (semver, PEP 440 simple, « v » initial) ; les préversions passent après. */
    public static function compareVersions(string $a, string $b): int
    {
        $na = ltrim($a, 'vV');
        $nb = ltrim($b, 'vV');
        $preA = 1 === preg_match('/[a-z]/i', (string) preg_replace('/^[\d.]+/', '', $na));
        $preB = 1 === preg_match('/[a-z]/i', (string) preg_replace('/^[\d.]+/', '', $nb));
        $cmp = version_compare(preg_replace('/[^0-9.].*$/', '', $na) ?: '0', preg_replace('/[^0-9.].*$/', '', $nb) ?: '0');

        return 0 !== $cmp ? $cmp : ($preA === $preB ? strnatcmp($na, $nb) : ($preA ? -1 : 1));
    }

    private static function count(int $n): string
    {
        return match (true) {
            $n >= 1_000_000 => str_replace('.', ',', sprintf('%.1f M', $n / 1_000_000)),
            $n >= 1_000 => str_replace('.', ',', sprintf('%.1f k', $n / 1_000)),
            default => (string) $n,
        };
    }
}
