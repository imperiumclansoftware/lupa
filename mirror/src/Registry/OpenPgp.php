<?php

namespace App\Registry;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Clé OpenPGP du miroir (Ed25519, RFC 4880 + EdDSA « legacy », comprise par gpgv et sqv) qui signe
 * les index apt régénérés par Romulus (InRelease, Release.gpg). Les postes du réseau isolé
 * l'installent une fois (/deb/romulus.gpg). Créée au premier besoin, jamais remplacée ensuite.
 */
final class OpenPgp
{
    private const string OID_ED25519 = "\x2B\x06\x01\x04\x01\xDA\x47\x0F\x01";
    private const int ALGO_EDDSA = 22;
    private const int HASH_SHA256 = 8;

    /** @var array{secret: string, public: string, created: int}|null */
    private ?array $key = null;

    public function __construct(
        #[Autowire('%env(MIRROR_KEY_DIR)%')]
        private readonly string $keyDir,
        private readonly string $name = 'Romulus (miroir hors ligne Lupa)',
    ) {
    }

    /** Clé publique binaire (pour « signed-by » d'apt). */
    public function publicKey(): string
    {
        $public = $this->publicPacketBody();
        $uid = $this->name.' <romulus@lupa.invalid>';
        $self = $this->signature(0x13, "\x99".pack('n', \strlen($public)).$public."\xB4".pack('N', \strlen($uid)).$uid, [self::subpacket(27, "\x03")]);

        return self::packet(6, $public).self::packet(13, $uid).self::packet(2, $self);
    }

    public function armoredPublicKey(): string
    {
        return self::armor('PUBLIC KEY BLOCK', $this->publicKey());
    }

    public function fingerprint(): string
    {
        $public = $this->publicPacketBody();

        return strtoupper(sha1("\x99".pack('n', \strlen($public)).$public));
    }

    /** Signature détachée (Release.gpg). */
    public function detached(string $data): string
    {
        return self::armor('SIGNATURE', self::packet(2, $this->signature(0x00, $data)));
    }

    /** Message signé en clair (InRelease). */
    public function clearsign(string $text): string
    {
        $text = rtrim($text, "\n");
        $lines = explode("\n", str_replace("\r\n", "\n", $text));
        // Texte canonique signé : fins de ligne CRLF, espaces de fin retirés, sans fin de ligne finale
        $canonical = implode("\r\n", array_map(static fn (string $l) => rtrim($l, " \t"), $lines));
        $signature = self::packet(2, $this->signature(0x01, $canonical));
        $escaped = implode("\n", array_map(static fn (string $l) => str_starts_with($l, '-') ? '- '.$l : $l, $lines));

        return "-----BEGIN PGP SIGNED MESSAGE-----\nHash: SHA256\n\n".$escaped."\n".self::armor('SIGNATURE', $signature);
    }

    /** @param list<string> $extraHashed sous-paquets supplémentaires (signature de clé) */
    private function signature(int $type, string $data, array $extraHashed = []): string
    {
        $key = $this->key();
        $fingerprint = hex2bin($this->fingerprint());
        $hashed = self::subpacket(2, pack('N', time())).self::subpacket(33, "\x04".$fingerprint).implode('', $extraHashed);
        $prefix = "\x04".\chr($type).\chr(self::ALGO_EDDSA).\chr(self::HASH_SHA256).pack('n', \strlen($hashed)).$hashed;
        $digest = hash('sha256', $data.$prefix."\x04\xFF".pack('N', \strlen($prefix)), true);
        $signature = sodium_crypto_sign_detached($digest, $key['secret']);
        $unhashed = self::subpacket(16, substr($fingerprint, -8));

        return $prefix.pack('n', \strlen($unhashed)).$unhashed.substr($digest, 0, 2)
            .self::mpi(substr($signature, 0, 32)).self::mpi(substr($signature, 32, 32));
    }

    private function publicPacketBody(): string
    {
        $key = $this->key();

        return "\x04".pack('N', $key['created']).\chr(self::ALGO_EDDSA).\chr(\strlen(self::OID_ED25519)).self::OID_ED25519.self::mpi("\x40".$key['public']);
    }

    /** @return array{secret: string, public: string, created: int} */
    private function key(): array
    {
        if (null !== $this->key) {
            return $this->key;
        }
        $file = $this->keyDir.'/apt-signing.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!\is_array($data) || !\is_string($data['seed'] ?? null) || 32 !== \strlen((string) base64_decode($data['seed'], true))) {
            if (is_file($file)) {
                throw new \RuntimeException(sprintf('Clé de signature apt illisible (%s) : restaurez-la depuis une sauvegarde, ou supprimez-la pour en créer une nouvelle (les postes devront réinstaller romulus.gpg).', $file));
            }
            $data = ['seed' => base64_encode(random_bytes(32)), 'created' => time()];
            if (!is_dir($this->keyDir) && !@mkdir($this->keyDir, 0o700, true) && !is_dir($this->keyDir)) {
                throw new \RuntimeException(sprintf('Impossible de créer %s.', $this->keyDir));
            }
            if (false === @file_put_contents($file, json_encode($data), \LOCK_EX)) {
                throw new \RuntimeException(sprintf('Impossible d\'enregistrer la clé de signature apt dans %s.', $this->keyDir));
            }
            @chmod($file, 0o600);
        }
        $pair = sodium_crypto_sign_seed_keypair((string) base64_decode($data['seed'], true));

        return $this->key = [
            'secret' => sodium_crypto_sign_secretkey($pair),
            'public' => sodium_crypto_sign_publickey($pair),
            'created' => (int) $data['created'],
        ];
    }

    private static function subpacket(int $type, string $data): string
    {
        return \chr(\strlen($data) + 1).\chr($type).$data;
    }

    /** Entier multiprécision : longueur en bits puis octets, sans zéros de tête. */
    private static function mpi(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ('' === $bytes) {
            return "\x00\x00";
        }
        $bits = (\strlen($bytes) - 1) * 8 + \strlen(decbin(\ord($bytes[0])));

        return pack('n', $bits).$bytes;
    }

    private static function packet(int $tag, string $body): string
    {
        $length = \strlen($body);
        $header = match (true) {
            $length < 192 => \chr($length),
            $length < 8384 => \chr((($length - 192) >> 8) + 192).\chr(($length - 192) & 0xFF),
            default => "\xFF".pack('N', $length),
        };

        return \chr(0xC0 | $tag).$header.$body;
    }

    private static function armor(string $type, string $data): string
    {
        $crc = 0xB704CE;
        for ($i = 0, $n = \strlen($data); $i < $n; ++$i) {
            $crc ^= \ord($data[$i]) << 16;
            for ($j = 0; $j < 8; ++$j) {
                $crc <<= 1;
                if ($crc & 0x1000000) {
                    $crc ^= 0x1864CFB;
                }
            }
        }
        $crc &= 0xFFFFFF;

        return sprintf("-----BEGIN PGP %s-----\n\n%s=%s\n-----END PGP %s-----\n", $type, chunk_split(base64_encode($data), 64, "\n"), base64_encode(substr(pack('N', $crc), 1)), $type);
    }
}
