<?php

namespace App\Search;

use App\Http\DownloadException;
use App\Resolver\Docker\ImageReference;
use App\Resolver\Docker\RegistryClient;
use App\Resolver\PypiResolver;
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
    ) {
    }

    /** @return list<Result> */
    public function search(Ecosystem $ecosystem, string $query, int $limit = 20): array
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
        };
    }

    /**
     * Versions publiées, de la plus récente à la plus ancienne (200 au plus).
     *
     * @return array{versions: list<string>, latest: string|null}
     */
    public function versions(Ecosystem $ecosystem, string $name): array
    {
        $versions = match ($ecosystem) {
            Ecosystem::Npm => $this->npmVersions($name),
            Ecosystem::Pypi => $this->pypiVersions($name),
            Ecosystem::Composer => $this->composerVersions($name),
            Ecosystem::Docker => $this->dockerTags($name),
        };

        return ['versions' => \array_slice($versions['versions'], 0, 200), 'latest' => $versions['latest']];
    }

    /**
     * Noms de paquets (ou de dépôts Docker Hub) correspondant à un motif.
     *
     * @return list<string>
     */
    public function names(Ecosystem $ecosystem, string $pattern): array
    {
        $names = match ($ecosystem) {
            Ecosystem::Npm => $this->npmNames($pattern),
            Ecosystem::Pypi => array_values(array_filter($this->pypiIndex(), static fn (string $n) => Glob::matches(self::pep503($pattern), self::pep503($n)))),
            Ecosystem::Composer => $this->composerNames($pattern),
            Ecosystem::Docker => $this->dockerRepositories($pattern),
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
                    Ecosystem::Npm => [sprintf('%s/%s', rtrim($this->npmRegistry, '/'), $name), ['Accept' => 'application/vnd.npm.install-v1+json']],
                    Ecosystem::Composer => [sprintf('https://repo.packagist.org/p2/%s.json', strtolower($name)), []],
                    Ecosystem::Pypi => [sprintf('%s/pypi/%s/json', rtrim($this->pypiIndex, '/'), rawurlencode($name)), []],
                    Ecosystem::Docker => throw new \LogicException('Sans objet pour Docker.'),
                };
                $responses[$name] = $this->http->request('GET', $url, ['headers' => $headers, 'timeout' => 60]);
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
        $data = $this->json(sprintf('%s/%s', rtrim($this->npmRegistry, '/'), $name), [], ['Accept' => 'application/vnd.npm.install-v1+json']);
        $versions = array_map('strval', array_keys($data['versions'] ?? []));
        usort($versions, static fn (string $a, string $b) => self::compareVersions($b, $a));

        return ['versions' => $versions, 'latest' => $data['dist-tags']['latest'] ?? ($versions[0] ?? null)];
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
    private function json(string $url, array $query = [], array $headers = []): array
    {
        try {
            $response = $this->http->request('GET', $url, ['query' => $query, 'headers' => $headers + ['Accept' => 'application/json'], 'timeout' => 30]);
            $status = $response->getStatusCode();
            if (429 === $status) {
                sleep(min(10, max(2, (int) ($response->getHeaders(false)['retry-after'][0] ?? 3))));
                $response = $this->http->request('GET', $url, ['query' => $query, 'headers' => $headers + ['Accept' => 'application/json'], 'timeout' => 30]);
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
