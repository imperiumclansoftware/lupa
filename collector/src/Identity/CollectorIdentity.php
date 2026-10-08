<?php

namespace App\Identity;

use Sas\Bundle\Signer;

/**
 * Identité du collecteur : identifiant, nom affiché et paire de clés Ed25519 qui signe les bundles.
 * Créée au premier usage dans var/identity (la clé secrète ne quitte jamais cette machine).
 */
final class CollectorIdentity
{
    /** @var array{id: string, name: string, publicKey: string}|null */
    private ?array $data = null;

    public function __construct(private readonly string $identityDir)
    {
    }

    public function id(): string
    {
        return $this->load()['id'];
    }

    public function name(): string
    {
        return $this->load()['name'];
    }

    public function rename(string $name): void
    {
        $data = $this->load();
        $data['name'] = $name;
        $this->save($data);
    }

    public function publicKey(): string
    {
        return $this->load()['publicKey'];
    }

    public function fingerprint(): string
    {
        return Signer::fingerprint($this->publicKey());
    }

    public function secretKey(): string
    {
        $this->load();
        $key = file_get_contents($this->identityDir.'/secret.key');
        if (false === $key) {
            throw new \RuntimeException('Clé de signature illisible.');
        }

        return trim($key);
    }

    /** @return array{id: string, name: string, publicKey: string} */
    private function load(): array
    {
        if ($this->data) {
            return $this->data;
        }
        $file = $this->identityDir.'/identity.json';
        if (is_file($file)) {
            return $this->data = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        }

        if (!is_dir($this->identityDir)) {
            mkdir($this->identityDir, 0o700, true);
        }
        $keys = Signer::generateKeyPair();
        $old = umask(0o077);
        file_put_contents($this->identityDir.'/secret.key', $keys['secretKey']);
        umask($old);
        $id = bin2hex(random_bytes(8));
        $this->save(['id' => $id, 'name' => 'Collecteur '.strtoupper(substr($id, 0, 4)), 'publicKey' => $keys['publicKey']]);

        return $this->data;
    }

    /** @param array{id: string, name: string, publicKey: string} $data */
    private function save(array $data): void
    {
        file_put_contents($this->identityDir.'/identity.json', json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        $this->data = $data;
    }
}
