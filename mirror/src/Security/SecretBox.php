<?php

namespace App\Security;

use Sas\Bundle\SecretSealer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffrement des secrets en base (clé secrète S3), avec une clé dérivée d'APP_SECRET : si APP_SECRET change,
 * la clé S3 est à ressaisir dans les Réglages.
 */
final class SecretBox implements SecretSealer
{
    private ?string $key = null;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
    ) {
    }

    public function seal(string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1:'.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    public function open(string $sealed): string
    {
        $raw = str_starts_with($sealed, 'v1:') ? base64_decode(substr($sealed, 3), true) : false;
        $plain = false === $raw ? false : sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
        if (false === $plain) {
            throw new \RuntimeException('Secret illisible : APP_SECRET a changé ? Ressaisissez-le dans les Réglages.');
        }

        return $plain;
    }

    private function key(): string
    {
        return $this->key ??= sodium_crypto_generichash('sas-storage/1'.$this->appSecret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
