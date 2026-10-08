<?php

namespace App\Identity;

use Sas\Bundle\Signer;

/**
 * Identité du collecteur : identifiant, nom affiché et paire de clés Ed25519 qui signe les bundles.
 * Rangée dans IDENTITY_DIR (secret.key + identity.json), créée au premier usage.
 *
 * La clé secrète n'est JAMAIS remplacée automatiquement : les miroirs font confiance à sa clé publique.
 * Une clé illisible, perdue ou différente de celle fournie bloque le démarrage avec une explication,
 * plutôt que de produire en silence des bundles que les miroirs refuseraient.
 */
final class CollectorIdentity
{
    /** @var array{id: string, name: string, publicKey: string}|null */
    private ?array $data = null;
    /** @var list<string> */
    private array $notices = [];

    public function __construct(private readonly string $identityDir)
    {
    }

    /**
     * Vérifie (et crée au besoin) l'identité au démarrage.
     *
     * @param string|null $providedSecretKey clé secrète fournie par l'environnement (réinstallation, migration)
     *
     * @return list<string> remarques à afficher
     */
    public function ensure(?string $providedSecretKey = null): array
    {
        $this->data = null;
        $this->notices = [];
        $this->load(null !== $providedSecretKey && '' !== trim($providedSecretKey) ? trim($providedSecretKey) : null);

        return $this->notices;
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

        return (string) $this->readSecret();
    }

    /** @return array{id: string, name: string, publicKey: string} */
    private function load(?string $provided = null): array
    {
        if ($this->data && null === $provided) {
            return $this->data;
        }
        if (null !== $provided && !Signer::isSecretKey($provided)) {
            throw new IdentityException('La clé de signature fournie (REMUS_SIGNING_KEY) est invalide : 88 caractères base64 attendus, tels que copiés depuis secret.key.');
        }

        $identity = $this->readIdentity();
        $secret = $this->readSecret();

        if (null === $secret) {
            if (null !== $identity && (null === $provided || Signer::publicKeyFromSecret($provided) !== $identity['publicKey'])) {
                throw new IdentityException(sprintf(
                    'Clé secrète absente de %s alors que l\'identité « %s » (empreinte %s) existe : les miroirs n\'accepteraient plus les bundles. '
                    .'Restaurez secret.key depuis une sauvegarde, ou fournissez-la dans REMUS_SIGNING_KEY. Pour repartir avec une nouvelle clé, supprimez aussi identity.json.',
                    $this->identityDir, $identity['name'], Signer::fingerprint($identity['publicKey']),
                ));
            }
            // Première installation : clé fournie (migration) ou nouvelle clé
            $secret = $provided ?? Signer::generateKeyPair()['secretKey'];
            $this->writeSecret($secret);
            $this->notices[] = null !== $provided ? 'Clé de signature importée depuis REMUS_SIGNING_KEY.' : 'Nouvelle clé de signature créée.';
        } elseif (null !== $provided && $provided !== $secret) {
            throw new IdentityException(sprintf(
                'REMUS_SIGNING_KEY diffère de la clé déjà installée dans %s (empreinte %s). La clé installée n\'est jamais remplacée automatiquement : '
                .'retirez la variable, ou supprimez secret.key et identity.json pour utiliser la clé fournie.',
                $this->identityDir, Signer::fingerprint(Signer::publicKeyFromSecret($secret)),
            ));
        }

        // La clé secrète fait foi : identity.json n'en est qu'un résumé (id, nom, clé publique)
        $publicKey = Signer::publicKeyFromSecret($secret);
        if (null === $identity) {
            $id = bin2hex(random_bytes(8));
            $identity = ['id' => $id, 'name' => 'Rémus '.strtoupper(substr($id, 0, 4)), 'publicKey' => $publicKey];
            $this->save($identity);
            $this->notices[] = 'Identité du collecteur créée.';
        } elseif ($identity['publicKey'] !== $publicKey) {
            $identity['publicKey'] = $publicKey;
            $this->save($identity);
            $this->notices[] = 'identity.json ne correspondait pas à secret.key : clé publique corrigée.';
        }

        return $this->data = $identity;
    }

    /** @return array{id: string, name: string, publicKey: string}|null */
    private function readIdentity(): ?array
    {
        $file = $this->identityDir.'/identity.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!\is_array($data) || !\is_string($data['id'] ?? null) || '' === $data['id'] || !\is_string($data['name'] ?? null)) {
            throw new IdentityException(sprintf('%s est illisible ou incomplet : restaurez-le depuis une sauvegarde, ou supprimez-le (il sera recréé à partir de secret.key).', $file));
        }

        return ['id' => $data['id'], 'name' => $data['name'], 'publicKey' => (string) ($data['publicKey'] ?? '')];
    }

    private function readSecret(): ?string
    {
        $file = $this->identityDir.'/secret.key';
        if (!is_file($file)) {
            return null;
        }
        $secret = trim((string) @file_get_contents($file));
        if (!Signer::isSecretKey($secret)) {
            throw new IdentityException(sprintf('Clé de signature %s corrompue : restaurez-la depuis une sauvegarde. Elle n\'est pas remplacée automatiquement, car les miroirs lui font confiance.', $file));
        }

        return $secret;
    }

    private function writeSecret(string $secret): void
    {
        if (!is_dir($this->identityDir) && !@mkdir($this->identityDir, 0o700, true)) {
            throw new IdentityException(sprintf('Impossible de créer %s (droits d\'écriture du volume ?).', $this->identityDir));
        }
        $old = umask(0o077);
        $written = @file_put_contents($this->identityDir.'/secret.key', $secret);
        umask($old);
        if (false === $written) {
            throw new IdentityException(sprintf('Impossible d\'écrire la clé de signature dans %s.', $this->identityDir));
        }
    }

    /** @param array{id: string, name: string, publicKey: string} $data */
    private function save(array $data): void
    {
        file_put_contents($this->identityDir.'/identity.json', json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        $this->data = $data;
    }
}
