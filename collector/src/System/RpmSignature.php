<?php

namespace App\System;

/**
 * Signature OpenPGP d'un paquet RPM, vérifiée avec gpgv (dépôts dnf tiers dont l'index n'est pas signé,
 * comme EPEL). Structure : en-tête « lead » de 96 octets, en-tête de signature, en-tête principal, contenu.
 *
 *  - rpm ≥ 4.14 (EL8 et suivants) : signature de l'en-tête principal (RSAHEADER), qui contient l'empreinte
 *    du contenu (PAYLOADDIGEST), recalculée ici ;
 *  - paquets plus anciens (EL7) : signature de l'en-tête et du contenu ensemble (PGP, GPG).
 */
final readonly class RpmSignature
{
    private const int TAG_DSAHEADER = 267;
    private const int TAG_RSAHEADER = 268;
    private const int TAG_PGP = 1002;
    private const int TAG_GPG = 1005;
    private const int TAG_PAYLOADDIGEST = 5092;
    private const int TAG_PAYLOADDIGESTALGO = 5093;
    /** Algorithmes de PAYLOADDIGESTALGO (valeurs PGPHASHALGO) */
    private const array DIGESTS = [2 => 'sha1', 8 => 'sha256', 9 => 'sha384', 10 => 'sha512', 11 => 'sha224'];

    public function __construct(private Signatures $signatures)
    {
    }

    /** @throws \RuntimeException paquet illisible, non signé ou mal signé */
    public function verify(string $file, string $armoredKey, string $what): void
    {
        $handle = @fopen($file, 'r') ?: throw new \RuntimeException(sprintf('%s : fichier illisible.', $what));
        try {
            if ("\xED\xAB\xEE\xDB" !== self::read($handle, 96, $what, 4)) {
                throw new \RuntimeException(sprintf('%s : ce n\'est pas un paquet RPM.', $what));
            }
            [$signatureHeader, $signatureTags] = self::header($handle, $what);
            // L'en-tête de signature est aligné sur 8 octets
            self::read($handle, (8 - \strlen($signatureHeader) % 8) % 8, $what);
            [$header, $tags] = self::header($handle, $what);
            $payloadOffset = (int) ftell($handle);

            $headerOnly = $signatureTags[self::TAG_RSAHEADER] ?? $signatureTags[self::TAG_DSAHEADER] ?? null;
            $digest = $tags[self::TAG_PAYLOADDIGEST] ?? null;
            if (null !== $headerOnly && null !== $digest) {
                $this->signatures->detachedWithKey($header, $headerOnly, $armoredKey, $what);
                $algo = self::DIGESTS[(int) ($tags[self::TAG_PAYLOADDIGESTALGO] ?? 8)] ?? throw new \RuntimeException(sprintf('%s : algorithme d\'empreinte inconnu.', $what));
                $context = hash_init($algo);
                hash_update_stream($context, $handle);
                if (!hash_equals(strtolower($digest), hash_final($context))) {
                    throw new \RuntimeException(sprintf('%s : contenu modifié (empreinte du contenu incorrecte).', $what));
                }

                return;
            }
            $full = $signatureTags[self::TAG_PGP] ?? $signatureTags[self::TAG_GPG] ?? null;
            if (null === $full) {
                throw new \RuntimeException(sprintf('%s : paquet non signé, refusé.', $what));
            }
            // En-tête et contenu signés ensemble : vérifiés depuis un fichier, sans tout charger en mémoire
            $data = tempnam(sys_get_temp_dir(), 'sas-rpm-');
            try {
                $out = fopen($data, 'w') ?: throw new \RuntimeException('Fichier temporaire impossible.');
                fwrite($out, $header);
                fseek($handle, $payloadOffset);
                stream_copy_to_stream($handle, $out);
                fclose($out);
                $this->signatures->detachedFileWithKey($data, $full, $armoredKey, $what);
            } finally {
                @unlink($data);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * En-tête RPM : octets bruts et valeurs des étiquettes utiles (binaire ou première chaîne, entier).
     *
     * @param resource $handle
     *
     * @return array{string, array<int, string>}
     */
    private static function header($handle, string $what): array
    {
        $intro = self::read($handle, 16, $what);
        if ("\x8E\xAD\xE8\x01" !== substr($intro, 0, 4)) {
            throw new \RuntimeException(sprintf('%s : en-tête RPM invalide.', $what));
        }
        $entries = self::u32($intro, 8);
        $size = self::u32($intro, 12);
        if ($entries > 100000 || $size > 256 << 20) {
            throw new \RuntimeException(sprintf('%s : en-tête RPM démesuré.', $what));
        }
        $index = self::read($handle, 16 * $entries, $what);
        $store = self::read($handle, $size, $what);
        $tags = [];
        for ($i = 0; $i < $entries; ++$i) {
            [$tag, $type, $offset, $count] = [self::u32($index, 16 * $i), self::u32($index, 16 * $i + 4), self::u32($index, 16 * $i + 8), self::u32($index, 16 * $i + 12)];
            $tags[$tag] = match ($type) {
                // INT32
                4 => (string) self::u32($store, $offset),
                // BIN
                7 => substr($store, $offset, $count),
                // STRING, STRING_ARRAY, I18NSTRING : première chaîne
                6, 8, 9 => (string) strstr(substr($store, $offset), "\0", true),
                default => '',
            };
        }

        return [$intro.$index.$store, $tags];
    }

    /** @param resource $handle */
    private static function read($handle, int $length, string $what, ?int $keep = null): string
    {
        if ($length <= 0) {
            return '';
        }
        $data = (string) fread($handle, $length);
        if (\strlen($data) !== $length) {
            throw new \RuntimeException(sprintf('%s : paquet RPM tronqué.', $what));
        }

        return null === $keep ? $data : substr($data, 0, $keep);
    }

    /** Entier 32 bits gros-boutiste à la position donnée ; 0 si les octets manquent. */
    private static function u32(string $bytes, int $offset): int
    {
        $value = unpack('N', substr($bytes, $offset, 4));

        return \is_array($value) && \is_int($value[1] ?? null) ? $value[1] : 0;
    }
}
