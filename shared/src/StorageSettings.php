<?php

namespace Sas\Bundle;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Configuration du stockage (Réglages › Stockage), dans la table « setting » : moteur actif (disque ou S3)
 * et paramètres S3, clé secrète chiffrée. Relue entre deux requêtes ou deux tâches du worker.
 */
final class StorageSettings implements ResetInterface
{
    public const string ACTIVE = 'storage.active';
    public const string S3 = 'storage.s3';

    private ?BlobBackend $backend = null;
    private bool $loaded = false;

    public function __construct(
        private readonly Connection $db,
        private readonly SecretSealer $sealer,
        private readonly string $tempDir,
        private readonly ?HttpClientInterface $http = null,
    ) {
    }

    /** « local » ou « s3 » */
    public function active(): string
    {
        return 's3' === $this->get(self::ACTIVE) && null !== $this->s3() ? 's3' : 'local';
    }

    /**
     * Paramètres S3 enregistrés, sans la clé secrète.
     *
     * @return array{endpoint: string, region: string, bucket: string, prefix: string, pathStyle: bool, accessKey: string, hasSecret: bool}|null
     */
    public function s3(): ?array
    {
        $data = json_decode((string) $this->get(self::S3), true);
        if (!\is_array($data) || !\is_string($data['endpoint'] ?? null) || !\is_string($data['bucket'] ?? null)) {
            return null;
        }
        $text = static fn (mixed $v): string => \is_string($v) ? $v : '';

        return [
            'endpoint' => $data['endpoint'],
            'region' => $text($data['region'] ?? null),
            'bucket' => $data['bucket'],
            'prefix' => $text($data['prefix'] ?? null),
            'pathStyle' => (bool) ($data['pathStyle'] ?? true),
            'accessKey' => $text($data['accessKey'] ?? null),
            'hasSecret' => \is_string($data['secret'] ?? null) && '' !== $data['secret'],
        ];
    }

    /**
     * @param array{endpoint: string, region: string, bucket: string, prefix: string, pathStyle: bool, accessKey: string} $config
     * @param string|null                                                                                                 $secret null = inchangée
     */
    public function saveS3(array $config, ?string $secret): void
    {
        $current = json_decode((string) $this->get(self::S3), true);
        $sealed = null !== $secret ? $this->sealer->seal($secret) : (\is_array($current) && \is_string($current['secret'] ?? null) ? $current['secret'] : '');
        $this->set(self::S3, (string) json_encode($config + ['secret' => $sealed], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        $this->reset();
    }

    /**
     * Paramètres S3 saisis : valeurs nettoyées et clé secrète (null = inchangée), ou message d'erreur.
     *
     * @param array<mixed> $input
     *
     * @return array{array{endpoint: string, region: string, bucket: string, prefix: string, pathStyle: bool, accessKey: string}, ?string}|string
     */
    public static function validate(array $input): array|string
    {
        $text = static fn (string $name): string => \is_string($input[$name] ?? null) ? trim($input[$name]) : '';
        $endpoint = rtrim($text('endpoint'), '/');
        if (!preg_match('#^https?://[^/\s]+$#i', $endpoint) || false === filter_var($endpoint, \FILTER_VALIDATE_URL)) {
            return 'Point d\'accès invalide : attendu http(s)://hôte[:port], sans chemin (ex. https://s3.example.com ou http://minio:9000).';
        }
        $bucket = $text('bucket');
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket)) {
            return 'Nom de bucket invalide : 3 à 63 caractères, minuscules, chiffres, points et tirets.';
        }
        $prefix = trim($text('prefix'), '/');
        // Segments « . » et « .. » refusés : un préfixe ne remonte jamais d'un dossier
        if ('' !== $prefix && (!preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#', $prefix) || preg_match('#(^|/)\.+(/|$)#', $prefix))) {
            return 'Préfixe invalide : lettres, chiffres, « . _ - » et « / » entre les dossiers (ex. lupa/remus).';
        }
        $region = $text('region');
        if ('' !== $region && !preg_match('/^[a-z0-9-]{2,40}$/', $region)) {
            return 'Région invalide (ex. eu-west-3, us-east-1).';
        }
        $accessKey = $text('accessKey');
        if ('' === $accessKey || \strlen($accessKey) > 256) {
            return 'Saisissez l\'identifiant de la clé d\'accès.';
        }
        $secret = $text('secretKey');

        return [[
            'endpoint' => $endpoint,
            'region' => $region,
            'bucket' => $bucket,
            'prefix' => $prefix,
            'pathStyle' => (bool) ($input['pathStyle'] ?? true),
            'accessKey' => $accessKey,
        ], '' !== $secret ? $secret : null];
    }

    /** Clé secrète enregistrée (bouton Tester sans ressaisie) */
    public function hasSecret(): bool
    {
        return null !== $this->secret();
    }

    public function activate(string $engine): void
    {
        $this->set(self::ACTIVE, 's3' === $engine ? 's3' : 'local');
        $this->reset();
    }

    /** Moteur actif ; null = disque (moteur par défaut de BlobStore). */
    public function backend(): ?BlobBackend
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $this->backend = 's3' === $this->active() ? $this->s3Backend() : null;
        }

        return $this->backend;
    }

    /**
     * Moteur S3 des paramètres enregistrés, ou de paramètres saisis (bouton Tester).
     *
     * @param array{endpoint: string, region: string, bucket: string, prefix: string, pathStyle: bool, accessKey: string}|null $config
     */
    public function s3Backend(?array $config = null, ?string $secret = null): S3BlobBackend
    {
        $stored = $this->s3();
        $config ??= $stored ?? throw new \RuntimeException('Stockage S3 non configuré.');
        $secret ??= $this->secret() ?? throw new \RuntimeException('Clé secrète S3 absente.');

        return S3BlobBackend::create($config, $secret, $this->tempDir, $this->http);
    }

    public function reset(): void
    {
        $this->loaded = false;
        $this->backend = null;
    }

    private function secret(): ?string
    {
        $data = json_decode((string) $this->get(self::S3), true);

        return \is_array($data) && \is_string($data['secret'] ?? null) && '' !== $data['secret'] ? $this->sealer->open($data['secret']) : null;
    }

    private function get(string $name): ?string
    {
        try {
            $value = $this->db->fetchOne('SELECT value FROM setting WHERE name = ?', [$name]);
        } catch (\Throwable) {
            return null; // base pas encore migrée : stockage sur disque
        }

        return \is_string($value) ? $value : null;
    }

    private function set(string $name, string $value): void
    {
        $this->db->executeStatement('INSERT INTO setting (name, value) VALUES (?, ?) ON CONFLICT (name) DO UPDATE SET value = excluded.value', [$name, $value]);
    }
}
