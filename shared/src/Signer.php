<?php

namespace Sas\Bundle;

/**
 * Signature Ed25519 des manifestes : le miroir n'importe que les bundles produits par un collecteur
 * dont il connaît la clé publique (la clé USB est un vecteur d'attaque vers le réseau isolé).
 */
final class Signer
{
    /** @return array{secretKey: string, publicKey: string} encodées en base64 */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'secretKey' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'publicKey' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];
    }

    public static function sign(string $data, string $secretKey): string
    {
        return base64_encode(sodium_crypto_sign_detached($data, self::decode($secretKey, \SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)));
    }

    public static function verify(string $data, string $signature, string $publicKey): bool
    {
        $sig = base64_decode(trim($signature), true);
        if (false === $sig || \SODIUM_CRYPTO_SIGN_BYTES !== \strlen($sig)) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, $data, self::decode($publicKey, \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES));
    }

    public static function isPublicKey(string $publicKey): bool
    {
        $raw = base64_decode(trim($publicKey), true);

        return false !== $raw && \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === \strlen($raw);
    }

    /** Empreinte courte et lisible à comparer à l'œil entre le collecteur et le miroir (ex. 3F2A-91C0-…). */
    public static function fingerprint(string $publicKey): string
    {
        $hex = strtoupper(substr(hash('sha256', self::decode($publicKey, \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)), 0, 20));

        return implode('-', str_split($hex, 4));
    }

    private static function decode(string $key, int $length): string
    {
        $raw = base64_decode(trim($key), true);
        if (false === $raw || $length !== \strlen($raw)) {
            throw new \InvalidArgumentException('Clé de signature invalide.');
        }

        return $raw;
    }
}
