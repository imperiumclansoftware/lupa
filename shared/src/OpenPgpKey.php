<?php

namespace Sas\Bundle;

/**
 * Clé publique OpenPGP fournie en ASCII armor (dépôt apt ou dnf tiers) : contrôle de forme et empreinte v4,
 * affichée pour que l'administrateur la compare à celle publiée par l'éditeur du dépôt.
 */
final class OpenPgpKey
{
    /** Données binaires des blocs « PUBLIC KEY BLOCK » (vide si le texte n'en contient pas). */
    public static function dearmor(string $armored): string
    {
        preg_match_all('/-----BEGIN PGP PUBLIC KEY BLOCK-----\R(.*?)-----END PGP PUBLIC KEY BLOCK-----/s', $armored, $blocks);
        $binary = '';
        foreach ($blocks[1] as $block) {
            // En-têtes (Version: …), ligne vide, données base64, somme de contrôle « =XXXX »
            $parts = preg_split('/\R\R/', $block, 2) ?: [$block];
            $body = (string) preg_replace('/^=[A-Za-z0-9+\/]{4}\s*$/m', '', $parts[\count($parts) - 1]);
            $binary .= (string) base64_decode((string) preg_replace('/\s+/', '', $body), true);
        }

        return $binary;
    }

    /** Empreinte v4 (40 caractères hexadécimaux, majuscules) de la clé principale ; null si illisible. */
    public static function fingerprint(string $armored): ?string
    {
        $data = self::dearmor($armored);
        $packet = self::firstPacket($data);
        // Paquet « clé publique » (tag 6), version 4
        if (null === $packet || 6 !== $packet['tag'] || '' === $packet['body'] || 4 !== \ord($packet['body'][0])) {
            return null;
        }

        return strtoupper(sha1("\x99".pack('n', \strlen($packet['body'])).$packet['body']));
    }

    /** @return array{tag: int, body: string}|null */
    private static function firstPacket(string $data): ?array
    {
        if (\strlen($data) < 2 || 0 === (\ord($data[0]) & 0x80)) {
            return null;
        }
        $first = \ord($data[0]);
        if ($first & 0x40) {
            // Format récent : longueur sur 1, 2 ou 5 octets
            $tag = $first & 0x3F;
            $l0 = \ord($data[1]);
            if ($l0 < 192) {
                [$length, $offset] = [$l0, 2];
            } elseif ($l0 < 224) {
                [$length, $offset] = [(($l0 - 192) << 8) + \ord($data[2] ?? "\0") + 192, 3];
            } elseif (255 === $l0) {
                [$length, $offset] = [self::uint('N', substr($data, 2, 4)), 6];
            } else {
                return null;
            }
        } else {
            // Ancien format : longueur sur 1, 2 ou 4 octets selon les deux bits de poids faible
            $tag = ($first >> 2) & 0x0F;
            $size = [1, 2, 4][$first & 0x03] ?? null;
            if (null === $size) {
                return null;
            }
            $length = self::uint([1 => 'C', 2 => 'n', 4 => 'N'][$size], substr($data, 1, $size));
            $offset = 1 + $size;
        }
        if (\strlen($data) < $offset + $length) {
            return null;
        }

        return ['tag' => $tag, 'body' => substr($data, $offset, $length)];
    }

    /** Entier non signé gros-boutiste (unpack C, n ou N) ; 0 si les octets manquent. */
    private static function uint(string $format, string $bytes): int
    {
        $value = unpack($format, $bytes);

        return \is_array($value) && \is_int($value[1] ?? null) ? $value[1] : 0;
    }
}
