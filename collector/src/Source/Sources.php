<?php

namespace App\Source;

use App\Entity\Source;
use App\Repository\SourceRepository;
use Sas\Bundle\Ecosystem;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Dépôts sources actifs et configuration des outils qui en découle :
 *   - npm : .npmrc (registre par portée, registre par défaut, authentification) ;
 *   - pip : --index-url / --extra-index-url ;
 *   - Composer : dépôts « composer » et COMPOSER_AUTH ;
 *   - Docker et téléchargements HTTP : identifiants choisis d'après l'hôte.
 */
final class Sources implements ResetInterface
{
    /** @var list<Source>|null */
    private ?array $enabled = null;

    public function __construct(
        private readonly SourceRepository $sources,
        private readonly SecretBox $box,
    ) {
    }

    /** @return list<Source> */
    public function enabled(?Ecosystem $ecosystem = null): array
    {
        $this->enabled ??= $this->sources->findBy(['enabled' => true], ['id' => 'ASC']);

        return array_values(array_filter($this->enabled, static fn (Source $s) => null === $ecosystem || $s->getEcosystem() === $ecosystem));
    }

    /** Oublie le cache (après une modification des réglages, et entre deux tâches du worker). */
    public function reset(): void
    {
        $this->enabled = null;
    }

    public function secret(Source $source): ?string
    {
        return null === $source->getSecret() ? null : $this->box->open($source->getSecret());
    }

    /** Dépôt qui remplace le dépôt officiel de l'écosystème, s'il y en a un. */
    public function replacement(Ecosystem $ecosystem): ?Source
    {
        foreach ($this->enabled($ecosystem) as $source) {
            if ($source->replacesDefault() && (Ecosystem::Npm !== $ecosystem || null === $source->getScope())) {
                return $source;
            }
        }

        return null;
    }

    // ---- npm --------------------------------------------------------------------------------

    /** Registre npm effectif pour un paquet (@acme/x → registre de la portée @acme). */
    public function npmRegistry(string $package, string $default): string
    {
        if (str_starts_with($package, '@')) {
            $scope = strtolower(explode('/', $package)[0]);
            foreach ($this->enabled(Ecosystem::Npm) as $source) {
                if ($source->getScope() === $scope) {
                    return $source->getUrl();
                }
            }
        }

        return $this->replacement(Ecosystem::Npm)?->getUrl() ?? rtrim($default, '/');
    }

    /** Contenu d'un .npmrc reprenant les dépôts privés ; null s'il n'y en a pas. */
    public function npmrc(string $default): ?string
    {
        $sources = $this->enabled(Ecosystem::Npm);
        if ([] === $sources) {
            return null;
        }
        $lines = ['registry='.($this->replacement(Ecosystem::Npm)?->getUrl() ?? rtrim($default, '/')).'/'];
        foreach ($sources as $source) {
            if (null !== $source->getScope()) {
                $lines[] = $source->getScope().':registry='.$source->getUrl().'/';
            }
            $prefix = '//'.preg_replace('#^https?://#', '', $source->getUrl()).'/:';
            $secret = $this->secret($source);
            if (Source::AUTH_TOKEN === $source->getAuthType() && null !== $secret) {
                $lines[] = $prefix.'_authToken='.$secret;
            } elseif (Source::AUTH_BASIC === $source->getAuthType() && null !== $secret) {
                $lines[] = $prefix.'_auth='.base64_encode($source->getUsername().':'.$secret);
            }
        }

        return implode("\n", $lines)."\n";
    }

    // ---- pip --------------------------------------------------------------------------------

    /**
     * Options d'index de pip (adresses des index « simple », identifiants inclus).
     *
     * @return list<string>
     */
    public function pipIndexOptions(string $default): array
    {
        $replacement = $this->replacement(Ecosystem::Pypi);
        $options = ['--index-url', $replacement ? $this->pipUrl($replacement) : rtrim($default, '/').'/simple'];
        foreach ($this->enabled(Ecosystem::Pypi) as $source) {
            if ($source !== $replacement) {
                array_push($options, '--extra-index-url', $this->pipUrl($source));
            }
        }
        foreach ($this->enabled(Ecosystem::Pypi) as $source) {
            if (str_starts_with($source->getUrl(), 'http://')) {
                array_push($options, '--trusted-host', (string) parse_url($source->getUrl(), \PHP_URL_HOST));
            }
        }

        return $options;
    }

    private function pipUrl(Source $source): string
    {
        $url = $source->getUrl().'/';
        if (null === $credentials = $this->basicCredentials($source)) {
            return $url;
        }

        return (string) preg_replace('#^(https?://)#', '$1'.rawurlencode($credentials[0]).':'.rawurlencode($credentials[1]).'@', $url);
    }

    // ---- Composer ---------------------------------------------------------------------------

    /**
     * Section « repositories » de composer.json : dépôts privés d'abord (prioritaires), Packagist
     * désactivé si un dépôt le remplace.
     *
     * @return list<array<string, mixed>>
     */
    public function composerRepositories(): array
    {
        $repositories = [];
        foreach ($this->enabled(Ecosystem::Composer) as $source) {
            $repositories[] = ['type' => 'composer', 'url' => $source->getUrl()];
        }
        if ($this->replacement(Ecosystem::Composer)) {
            $repositories[] = ['packagist.org' => false];
        }

        return $repositories;
    }

    /** Dépôts privés en http:// : Composer les refuse sans secure-http=false. */
    public function composerInsecure(): bool
    {
        foreach ($this->enabled(Ecosystem::Composer) as $source) {
            if (str_starts_with($source->getUrl(), 'http://')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Identifiants des dépôts privés au format COMPOSER_AUTH.
     *
     * @param array<string, mixed> $auth configuration existante (github-oauth…)
     *
     * @return array<string, mixed>
     */
    public function composerAuth(array $auth = []): array
    {
        foreach ($this->enabled(Ecosystem::Composer) as $source) {
            $secret = $this->secret($source);
            if (null === $secret) {
                continue;
            }
            $host = $source->host();
            if (Source::AUTH_TOKEN === $source->getAuthType()) {
                $auth['bearer'][$host] = $secret;
            } elseif (Source::AUTH_BASIC === $source->getAuthType()) {
                $auth['http-basic'][$host] = ['username' => (string) $source->getUsername(), 'password' => $secret];
            }
        }

        return $auth;
    }

    // ---- Docker -----------------------------------------------------------------------------

    /**
     * Identifiants d'un registre Docker (hôte : ghcr.io, harbor.local:5000…).
     *
     * @return array{string, string}|null
     */
    public function dockerCredentials(string $registry): ?array
    {
        foreach ($this->enabled(Ecosystem::Docker) as $source) {
            if ($source->host() === strtolower($registry) || ('docker.io' === $registry && $source->replacesDefault())) {
                return $this->basicCredentials($source);
            }
        }

        return null;
    }

    /** Miroir à utiliser à la place de Docker Hub (https://hôte), s'il y en a un. */
    public function dockerHubMirror(): ?string
    {
        return $this->replacement(Ecosystem::Docker)?->getUrl();
    }

    // ---- HTTP -------------------------------------------------------------------------------

    /**
     * Options d'authentification HttpClient pour une adresse servie par un dépôt privé.
     *
     * @return array<string, mixed>
     */
    public function httpOptions(string $url): array
    {
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST)).(($port = parse_url($url, \PHP_URL_PORT)) ? ':'.$port : '');
        $best = null;
        foreach ($this->enabled() as $source) {
            if (Source::AUTH_NONE === $source->getAuthType() || Ecosystem::Docker === $source->getEcosystem() || $source->host() !== $host) {
                continue;
            }
            // Le dépôt dont l'adresse est le plus long préfixe de l'URL (plusieurs dépôts sur un même Nexus)
            $path = (string) parse_url($source->getUrl(), \PHP_URL_PATH);
            $score = str_starts_with((string) parse_url($url, \PHP_URL_PATH), $path) ? \strlen($path) + 1 : 0;
            if (null === $best || $score > $best[0]) {
                $best = [$score, $source];
            }
        }

        return $best ? $this->authOptions($best[1]) : [];
    }

    /** @return array<string, mixed> */
    public function authOptions(Source $source): array
    {
        $secret = $this->secret($source);
        if (null === $secret) {
            return [];
        }
        if (Source::AUTH_TOKEN === $source->getAuthType() && \in_array($source->getEcosystem(), [Ecosystem::Npm, Ecosystem::Composer], true)) {
            return ['auth_bearer' => $secret];
        }

        return ['auth_basic' => $this->basicCredentials($source)];
    }

    /**
     * Identifiant et mot de passe (un jeton seul devient « __token__ », convention de PyPI et devpi ;
     * pour Docker, l'identifiant est demandé par l'interface).
     *
     * @return array{string, string}|null
     */
    private function basicCredentials(Source $source): ?array
    {
        $secret = $this->secret($source);
        if (Source::AUTH_NONE === $source->getAuthType() || null === $secret) {
            return null;
        }

        return [$source->getUsername() ?? '__token__', $secret];
    }
}
