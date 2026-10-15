<?php

namespace App\Http;

use App\Source\Sources;
use Sas\Bundle\BlobStore;
use Sas\Bundle\IntegrityException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Télécharge vers le stockage par empreinte en vérifiant les sommes de contrôle publiées par le registre.
 */
final readonly class Downloader
{
    public function __construct(
        private HttpClientInterface $http,
        private BlobStore $store,
        private ?Sources $sources = null,
    ) {
    }

    /**
     * @param array<string, mixed>  $options options HttpClient (en-têtes, authentification…)
     * @param array<string, string> $checks  algorithme => empreinte hexadécimale attendue (sha512, sha1…)
     *
     * @return array{sha256: string, size: int}
     */
    public function fetch(string $url, array $options = [], ?string $sha256 = null, array $checks = []): array
    {
        if (null !== $sha256 && $this->store->has($sha256)) {
            return ['sha256' => $sha256, 'size' => $this->store->size($sha256)];
        }

        $contexts = [];
        foreach ($checks as $algo => $expected) {
            $contexts[$algo] = hash_init($algo);
        }

        try {
            $response = $this->http->request('GET', $url, $this->withAuth($url, $options) + ['timeout' => 60, 'max_duration' => 0]);
            if (200 !== $status = $response->getStatusCode()) {
                throw new DownloadException(sprintf('HTTP %d pour %s', $status, self::redact($url)));
            }
            $chunks = (function () use ($response, $contexts) {
                foreach ($this->http->stream($response) as $chunk) {
                    $data = $chunk->getContent();
                    if ('' !== $data) {
                        foreach ($contexts as $ctx) {
                            hash_update($ctx, $data);
                        }
                        yield $data;
                    }
                }
            })();

            // Écriture dans un fichier temporaire ; on vérifie les empreintes annexes avant de le ranger
            $tmp = $this->store->tempFile();
            $out = fopen($tmp, 'w');
            foreach ($chunks as $data) {
                fwrite($out, $data);
            }
            fclose($out);
        } catch (ExceptionInterface $e) {
            throw new DownloadException(sprintf('Téléchargement impossible de %s : %s', self::redact($url), $e->getMessage()), previous: $e);
        }

        foreach ($checks as $algo => $expected) {
            $actual = hash_final($contexts[$algo]);
            if (!hash_equals(strtolower($expected), $actual)) {
                @unlink($tmp);
                throw new IntegrityException(sprintf('Somme %s incorrecte pour %s : le fichier reçu ne correspond pas à celui publié par le registre.', $algo, self::redact($url)));
            }
        }

        return $this->store->putFile($tmp, $sha256);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    public function json(string $url, array $options = []): array
    {
        try {
            $response = $this->http->request('GET', $url, $this->withAuth($url, $options) + ['timeout' => 60]);
            if (200 !== $status = $response->getStatusCode()) {
                throw new DownloadException(sprintf('HTTP %d pour %s', $status, self::redact($url)), $status);
            }

            return $response->toArray();
        } catch (DownloadException $e) {
            throw $e;
        } catch (ExceptionInterface $e) {
            throw new DownloadException(sprintf('Requête impossible vers %s : %s', self::redact($url), $e->getMessage()), previous: $e);
        }
    }

    /**
     * Identifiants du dépôt privé qui sert cette adresse, sauf si l'appelant fournit déjà les siens.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function withAuth(string $url, array $options): array
    {
        if (null === $this->sources || isset($options['auth_basic']) || isset($options['auth_bearer']) || isset($options['headers']['Authorization'])) {
            return $options;
        }

        return $options + $this->sources->httpOptions($url);
    }

    /** Retire d'éventuels identifiants d'une URL avant de l'afficher. */
    public static function redact(string $url): string
    {
        return (string) preg_replace('#//[^/@]+@#', '//***@', $url);
    }
}
