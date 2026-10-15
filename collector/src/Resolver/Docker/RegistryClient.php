<?php

namespace App\Resolver\Docker;

use App\Http\DownloadException;
use App\Http\Downloader;
use App\Source\Sources;
use Sas\Bundle\BlobStore;
use Sas\Bundle\IntegrityException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client minimal de l'API OCI Distribution (lecture seule) : manifestes et blobs, avec l'authentification
 * par jeton (Bearer) utilisée par Docker Hub, GHCR, Quay, etc.
 */
final class RegistryClient
{
    public const array MANIFEST_TYPES = [
        'application/vnd.oci.image.index.v1+json',
        'application/vnd.docker.distribution.manifest.list.v2+json',
        'application/vnd.oci.image.manifest.v1+json',
        'application/vnd.docker.distribution.manifest.v2+json',
    ];

    /** @var array<string, string> jetons par registre et dépôt */
    private array $tokens = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly Downloader $downloader,
        private readonly BlobStore $store,
        #[Autowire('%env(DOCKERHUB_USERNAME)%')]
        private readonly string $dockerhubUsername,
        #[Autowire('%env(DOCKERHUB_TOKEN)%')]
        private readonly string $dockerhubToken,
        private readonly ?Sources $sources = null,
    ) {
    }

    /**
     * Télécharge un manifeste (ou un index multi-plateformes) et le range dans le stockage.
     *
     * @return array{digest: string, mediaType: string, size: int, document: array<string, mixed>}
     */
    public function manifest(ImageReference $image, string $reference): array
    {
        $url = sprintf('%s/v2/%s/manifests/%s', $this->endpoint($image), $image->repository, $reference);
        $response = $this->request($image, $url, ['Accept' => implode(', ', self::MANIFEST_TYPES)]);
        $status = $response->getStatusCode();
        if (404 === $status) {
            throw new DownloadException(sprintf('Image ou étiquette introuvable : %s.', $image));
        }
        if (200 !== $status) {
            throw new DownloadException(sprintf('Registre %s : HTTP %d pour le manifeste de %s.', $image->registry, $status, $image));
        }

        $body = $response->getContent();
        $digest = 'sha256:'.hash('sha256', $body);
        $announced = $response->getHeaders()['docker-content-digest'][0] ?? null;
        if (str_starts_with($reference, 'sha256:') && $reference !== $digest) {
            throw new IntegrityException(sprintf('Manifeste altéré pour %s (empreinte %s au lieu de %s).', $image, $digest, $reference));
        }
        if (null !== $announced && str_starts_with($announced, 'sha256:') && $announced !== $digest) {
            throw new IntegrityException(sprintf('Manifeste altéré pour %s.', $image));
        }

        $document = json_decode($body, true);
        if (!\is_array($document)) {
            throw new DownloadException(sprintf('Manifeste illisible pour %s.', $image));
        }
        $mediaType = $document['mediaType'] ?? explode(';', $response->getHeaders()['content-type'][0] ?? '')[0];
        if (!\in_array($mediaType, self::MANIFEST_TYPES, true)) {
            throw new DownloadException(sprintf('Format de manifeste non pris en charge pour %s : %s (images Docker v1 obsolètes ?).', $image, $mediaType ?: 'inconnu'));
        }
        $this->store->putString($body, substr($digest, 7));

        return ['digest' => $digest, 'mediaType' => $mediaType, 'size' => \strlen($body), 'document' => $document];
    }

    /**
     * Étiquettes d'une image (API OCI tags/list, avec pagination).
     *
     * @return list<string>
     */
    public function tags(ImageReference $image, int $max = 2000): array
    {
        $tags = [];
        $url = sprintf('%s/v2/%s/tags/list?n=1000', $this->endpoint($image), $image->repository);
        while (null !== $url && \count($tags) < $max) {
            $response = $this->request($image, $url, ['Accept' => 'application/json']);
            if (200 !== $response->getStatusCode()) {
                throw new DownloadException(sprintf('Étiquettes de %s indisponibles (HTTP %d).', $image->repository, $response->getStatusCode()));
            }
            array_push($tags, ...array_map('strval', $response->toArray()['tags'] ?? []));
            // Lien « next » de l'en-tête Link (RFC 5988)
            $link = $response->getHeaders()['link'][0] ?? '';
            $url = preg_match('/<([^>]+)>;\s*rel="next"/', $link, $m) ? (str_starts_with($m[1], 'http') ? $m[1] : $this->endpoint($image).$m[1]) : null;
        }

        return $tags;
    }

    /** @return array{sha256: string, size: int} */
    public function blob(ImageReference $image, string $digest): array
    {
        if (!preg_match('/^sha256:([a-f0-9]{64})$/', $digest, $m)) {
            throw new DownloadException(sprintf('Algorithme d\'empreinte non pris en charge : %s.', $digest));
        }
        if ($this->store->has($m[1])) {
            return ['sha256' => $m[1], 'size' => $this->store->size($m[1])];
        }
        $url = sprintf('%s/v2/%s/blobs/%s', $this->endpoint($image), $image->repository, $digest);
        // Jeton obtenu au préalable (le manifeste est toujours lu avant les blobs)
        $token = $this->tokens[$image->registry.'/'.$image->repository] ?? null;

        return $this->downloader->fetch($url, $token ? ['headers' => ['Authorization' => $token]] : [], sha256: $m[1]);
    }

    /** Adresse de l'API : un miroir de Docker Hub configuré dans les dépôts sources le remplace. */
    private function endpoint(ImageReference $image): string
    {
        return 'docker.io' === $image->registry ? ($this->sources?->dockerHubMirror() ?? $image->endpoint()) : $image->endpoint();
    }

    /** @param array<string, string> $headers */
    private function request(ImageReference $image, string $url, array $headers): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $key = $image->registry.'/'.$image->repository;
        if (isset($this->tokens[$key])) {
            $headers['Authorization'] = $this->tokens[$key];
        }
        $response = $this->http->request('GET', $url, ['headers' => $headers, 'timeout' => 60]);
        if (401 !== $response->getStatusCode()) {
            return $response;
        }

        $challenge = $response->getHeaders(false)['www-authenticate'][0] ?? '';
        $this->tokens[$key] = $this->authenticate($image, $challenge);
        $headers['Authorization'] = $this->tokens[$key];

        $response = $this->http->request('GET', $url, ['headers' => $headers, 'timeout' => 60]);
        if (401 === $response->getStatusCode() || 403 === $response->getStatusCode()) {
            throw new DownloadException(sprintf('Accès refusé par %s pour %s (image privée ? identifiants à renseigner).', $image->registry, $image));
        }

        return $response;
    }

    private function authenticate(ImageReference $image, string $challenge): string
    {
        $credentials = $this->sources?->dockerCredentials($image->registry)
            ?? ('docker.io' === $image->registry && '' !== $this->dockerhubUsername ? [$this->dockerhubUsername, $this->dockerhubToken] : null);

        if (str_starts_with(strtolower($challenge), 'basic')) {
            if (!$credentials) {
                throw new DownloadException(sprintf('Le registre %s exige une authentification.', $image->registry));
            }

            return 'Basic '.base64_encode(implode(':', $credentials));
        }

        preg_match_all('/(\w+)="([^"]*)"/', $challenge, $m, \PREG_SET_ORDER);
        $params = [];
        foreach ($m as [, $name, $value]) {
            $params[strtolower($name)] = $value;
        }
        if (!isset($params['realm'])) {
            throw new DownloadException(sprintf('Authentification du registre %s non prise en charge.', $image->registry));
        }

        $query = array_filter(['service' => $params['service'] ?? null, 'scope' => sprintf('repository:%s:pull', $image->repository)]);
        $options = ['query' => $query, 'timeout' => 30];
        if ($credentials) {
            $options['auth_basic'] = $credentials;
        }
        $response = $this->http->request('GET', $params['realm'], $options);
        if (200 !== $response->getStatusCode()) {
            throw new DownloadException(sprintf('Jeton refusé par %s (HTTP %d).', $image->registry, $response->getStatusCode()));
        }
        $data = $response->toArray();
        $token = $data['token'] ?? $data['access_token'] ?? null;
        if (!\is_string($token)) {
            throw new DownloadException(sprintf('Jeton absent de la réponse de %s.', $image->registry));
        }

        return 'Bearer '.$token;
    }
}
