<?php

namespace App\Source;

use App\Identity\CollectorIdentity;

/**
 * Chiffre les secrets des dépôts privés (XSalsa20-Poly1305). La clé est dérivée de la clé de signature
 * du collecteur : sauvegarder l'identité suffit, et une base volée seule ne révèle aucun mot de passe.
 */
final class SecretBox
{
    private ?string $key = null;

    public function __construct(private readonly CollectorIdentity $identity)
    {
    }

    public function seal(string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1:'.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    public function open(string $sealed): string
    {
        $raw = str_starts_with($sealed, 'v1:') ? base64_decode(substr($sealed, 3), true) : false;
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Secret de dépôt illisible.');
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
        if (false === $plain) {
            throw new \RuntimeException('Secret de dépôt indéchiffrable : la clé de signature du collecteur a changé ; ressaisissez-le.');
        }

        return $plain;
    }

    private function key(): string
    {
        return $this->key ??= sodium_crypto_generichash('sas-sources/1'.$this->identity->secretKey(), '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
