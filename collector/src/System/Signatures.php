<?php

namespace App\System;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Vérifie les signatures OpenPGP des index officiels (InRelease Debian, repomd.xml.asc) avec gpgv et les
 * clés publiques livrées avec Rémus (collector/resources/keys) : aucune clé n'est téléchargée.
 */
final class Signatures
{
    /** @var array<string, string> fichier de trousseau par jeu de clés */
    private array $keyrings = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/keys')]
        private readonly string $keyDir,
        #[Autowire('%env(GPGV_BIN)%')]
        private readonly string $gpgv = 'gpgv',
    ) {
    }

    /**
     * Message signé en clair (InRelease) : renvoie le texte authentifié, seul à utiliser.
     *
     * @param list<string> $keys noms des clés (sans .asc)
     */
    public function clearsigned(string $message, array $keys, string $what): string
    {
        $dir = $this->tempDir();
        try {
            file_put_contents($dir.'/in', $message);
            $this->run([$this->gpgv, '--status-fd', '1', '--keyring', $this->keyring($keys), '--output', $dir.'/out', $dir.'/in'], $what);

            return (string) file_get_contents($dir.'/out');
        } finally {
            $this->cleanup($dir);
        }
    }

    /** @param list<string> $keys */
    public function detached(string $data, string $signature, array $keys, string $what): void
    {
        $dir = $this->tempDir();
        try {
            file_put_contents($dir.'/data', $data);
            file_put_contents($dir.'/data.asc', $signature);
            $this->run([$this->gpgv, '--status-fd', '1', '--keyring', $this->keyring($keys), $dir.'/data.asc', $dir.'/data'], $what);
        } finally {
            $this->cleanup($dir);
        }
    }

    /**
     * Signature détachée vérifiée avec une clé fournie avec le fichier (clé de l'éditeur d'un provider
     * Terraform, publiée par le registre en HTTPS).
     */
    public function detachedWithKey(string $data, string $signature, string $armoredKey, string $what, bool $allowExpiredKey = false): void
    {
        $dir = $this->tempDir();
        try {
            file_put_contents($dir.'/keyring.gpg', self::dearmor($armoredKey));
            file_put_contents($dir.'/data', $data);
            file_put_contents($dir.'/data.sig', $signature);
            $this->run([$this->gpgv, '--status-fd', '1', '--keyring', $dir.'/keyring.gpg', $dir.'/data.sig', $dir.'/data'], $what, $allowExpiredKey);
        } finally {
            $this->cleanup($dir);
        }
    }

    /** Clé(s) au format binaire (trousseau), à partir des fichiers ASCII armor (plusieurs blocs possibles). */
    public static function dearmor(string $armored): string
    {
        preg_match_all('/-----BEGIN PGP PUBLIC KEY BLOCK-----\R(.*?)-----END PGP PUBLIC KEY BLOCK-----/s', $armored, $blocks);
        $binary = '';
        foreach ($blocks[1] as $block) {
            // En-têtes (Version: …), ligne vide, données base64, somme de contrôle « =XXXX »
            $parts = preg_split('/\R\R/', $block, 2);
            $body = 2 === \count($parts) ? $parts[1] : $parts[0];
            $body = (string) preg_replace('/^=[A-Za-z0-9+\/]{4}\s*$/m', '', $body);
            $binary .= (string) base64_decode((string) preg_replace('/\s+/', '', $body), true);
        }

        return $binary;
    }

    /** @param list<string> $keys */
    private function keyring(array $keys): string
    {
        $id = implode(',', $keys);
        if (isset($this->keyrings[$id]) && is_file($this->keyrings[$id])) {
            return $this->keyrings[$id];
        }
        $data = '';
        foreach ($keys as $key) {
            $file = $this->keyDir.'/'.$key.'.asc';
            if (!is_file($file)) {
                throw new \LogicException(sprintf('Clé de signature %s absente de Rémus.', $key));
            }
            $data .= self::dearmor((string) file_get_contents($file));
        }
        $path = sys_get_temp_dir().'/sas-keyring-'.sha1($id).'.gpg';
        file_put_contents($path, $data);

        return $this->keyrings[$id] = $path;
    }

    /** @param list<string> $command */
    private function run(array $command, string $what, bool $allowExpiredKey = false): void
    {
        $process = new Process($command, null, ['GNUPGHOME' => sys_get_temp_dir(), 'LC_ALL' => 'C']);
        $process->run();
        // Comme apt : au moins une signature valide d'une clé connue, aucune signature invalide
        // (les index sont souvent co-signés par une clé plus récente, absente du trousseau)
        $status = $process->getOutput();
        $errors = 0;
        foreach (explode("\n", $status) as $line) {
            $fields = explode(' ', trim($line));
            // ERRSIG <clé> <algo> <hachage> <classe> <date> <code> : code 9 = clé publique absente (toléré)
            if ('ERRSIG' === ($fields[1] ?? null) && '9' !== ($fields[7] ?? null)) {
                ++$errors;
            }
        }
        // Clé expirée (EXPKEYSIG) : signature mathématiquement valide (VALIDSIG), acceptée si permis
        $good = str_contains($status, '[GNUPG:] GOODSIG')
            || ($allowExpiredKey && str_contains($status, '[GNUPG:] EXPKEYSIG') && str_contains($status, '[GNUPG:] VALIDSIG'));
        if (!$good || str_contains($status, '[GNUPG:] BADSIG') || str_contains($status, '[GNUPG:] REVKEYSIG') || $errors > 0) {
            throw new \RuntimeException(sprintf("Signature invalide pour %s : index refusé.\n%s", $what, trim($process->getErrorOutput())));
        }
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/sas-gpgv-'.bin2hex(random_bytes(6));
        mkdir($dir, 0o700);

        return $dir;
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
