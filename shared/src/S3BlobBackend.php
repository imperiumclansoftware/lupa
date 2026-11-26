<?php

namespace Sas\Bundle;

use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\S3\S3Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stockage dans un bucket S3 (AWS, MinIO, Ceph, Garage…) : mêmes clés que sur disque, sous un préfixe
 * facultatif. Chaque envoi porte l'empreinte SHA-256 du fichier, vérifiée par le serveur S3.
 */
final readonly class S3BlobBackend implements BlobBackend
{
    /** Taille maximale d'un envoi en une seule requête (limite de PutObject) */
    private const int MAX_SINGLE_PUT = 5 * 1024 ** 3;

    public function __construct(
        private S3Client $client,
        private string $bucket,
        private string $prefix,
        private string $tempDir,
        private string $label,
    ) {
    }

    /**
     * @param array{endpoint: string, region: string, bucket: string, prefix: string, pathStyle: bool, accessKey: string} $config
     */
    public static function create(array $config, string $secretKey, string $tempDir, ?HttpClientInterface $http = null): self
    {
        $client = new S3Client([
            'endpoint' => $config['endpoint'],
            'region' => '' !== $config['region'] ? $config['region'] : 'us-east-1',
            'accessKeyId' => $config['accessKey'],
            'accessKeySecret' => $secretKey,
            'pathStyleEndpoint' => $config['pathStyle'] ? 'true' : 'false',
            // Corps envoyés d'un seul tenant : compatibles avec MinIO, Ceph et Garage
            'sendChunkedBody' => 'false',
        ], null, $http);

        return new self($client, $config['bucket'], trim($config['prefix'], '/'), $tempDir, sprintf('s3://%s%s (%s)', $config['bucket'], '' !== trim($config['prefix'], '/') ? '/'.trim($config['prefix'], '/') : '', $config['endpoint']));
    }

    public function name(): string
    {
        return 's3';
    }

    public function describe(): string
    {
        return $this->label;
    }

    public function has(string $key): bool
    {
        try {
            $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $this->full($key)])->resolve();

            return true;
        } catch (ClientException $e) {
            if (404 === $e->getResponse()->getStatusCode()) {
                return false;
            }
            throw $e;
        }
    }

    public function size(string $key): int
    {
        return (int) $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $this->full($key)])->getContentLength();
    }

    public function read(string $key): string
    {
        return $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $this->full($key)])->getBody()->getContentAsString();
    }

    public function stream(string $key, int $offset = 0, ?int $length = null)
    {
        $input = ['Bucket' => $this->bucket, 'Key' => $this->full($key)];
        if ($offset > 0 || null !== $length) {
            $input['Range'] = sprintf('bytes=%d-%s', $offset, null !== $length ? (string) ($offset + $length - 1) : '');
        }

        return $this->client->getObject($input)->getBody()->getContentAsResource();
    }

    public function store(string $file, string $key, string $sha256): void
    {
        $size = (int) filesize($file);
        if ($size > self::MAX_SINGLE_PUT) {
            throw new \RuntimeException(sprintf('Fichier %s de plus de 5 Gio : trop gros pour un envoi S3 en une requête.', $sha256));
        }
        $body = fopen($file, 'r') ?: throw new \RuntimeException(sprintf('Fichier %s illisible.', $file));
        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $this->full($key),
                'Body' => $body,
                'ContentLength' => $size,
                // Vérifiée par le serveur : un fichier altéré en route est refusé
                'ChecksumSHA256' => base64_encode((string) hex2bin($sha256)),
            ])->resolve();
        } finally {
            fclose($body);
        }
        @unlink($file);
    }

    public function delete(string $key): void
    {
        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $this->full($key)])->resolve();
    }

    public function move(string $from, string $to): void
    {
        $this->client->copyObject(['Bucket' => $this->bucket, 'Key' => $this->full($to), 'CopySource' => rawurlencode($this->bucket).'/'.str_replace('%2F', '/', rawurlencode($this->full($from)))])->resolve();
        $this->delete($from);
    }

    public function localFile(string $key): array
    {
        if (!is_dir($this->tempDir) && !@mkdir($this->tempDir, 0o770, true) && !is_dir($this->tempDir)) {
            throw new \RuntimeException(sprintf('Impossible de créer %s.', $this->tempDir));
        }
        $path = tempnam($this->tempDir, 's3-');
        $out = fopen($path, 'w') ?: throw new \RuntimeException('Fichier temporaire impossible.');
        try {
            stream_copy_to_stream($this->stream($key), $out);
        } finally {
            fclose($out);
        }

        return [$path, true];
    }

    public function keys(string $prefix): iterable
    {
        $start = '' !== $this->prefix ? \strlen($this->prefix) + 1 : 0;
        foreach ($this->client->listObjectsV2(['Bucket' => $this->bucket, 'Prefix' => $this->full(rtrim($prefix, '/').'/')])->getContents() as $object) {
            yield substr((string) $object->getKey(), $start);
        }
    }

    /** Vérifie l'accès : écriture, lecture et suppression d'un petit fichier de test. */
    public function probe(): string
    {
        $key = 'lupa-test/'.bin2hex(random_bytes(8));
        $content = 'Lupa : test d\'accès au bucket';
        $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $this->full($key), 'Body' => $content, 'ChecksumSHA256' => base64_encode(hash('sha256', $content, true))])->resolve();
        if ($this->read($key) !== $content) {
            throw new \RuntimeException('Le fichier relu diffère du fichier écrit.');
        }
        $this->delete($key);

        return sprintf('Écriture, lecture et suppression réussies dans %s.', $this->label);
    }

    private function full(string $key): string
    {
        return '' !== $this->prefix ? $this->prefix.'/'.$key : $key;
    }
}
