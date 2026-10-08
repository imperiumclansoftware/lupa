<?php

namespace Sas\Bundle;

/**
 * Stockage par empreinte : chaque fichier est rangé sous son sha256, une seule copie quel que soit
 * le nombre de paquets ou d'images qui le référencent (les couches Docker communes sont partagées).
 */
final class BlobStore
{
    public function __construct(private readonly string $root)
    {
    }

    public static function isDigest(string $sha256): bool
    {
        return 1 === preg_match('/^[a-f0-9]{64}$/', $sha256);
    }

    public function path(string $sha256): string
    {
        if (!self::isDigest($sha256)) {
            throw new \InvalidArgumentException(sprintf('Empreinte sha256 invalide : "%s".', $sha256));
        }

        return sprintf('%s/sha256/%s/%s', $this->root, substr($sha256, 0, 2), $sha256);
    }

    public function has(string $sha256): bool
    {
        return self::isDigest($sha256) && is_file($this->path($sha256));
    }

    public function size(string $sha256): int
    {
        return (int) filesize($this->path($sha256));
    }

    public function read(string $sha256): string
    {
        $data = file_get_contents($this->path($sha256));
        if (false === $data) {
            throw new \RuntimeException(sprintf('Fichier %s illisible.', $sha256));
        }

        return $data;
    }

    /** Fichier temporaire dans le même système de fichiers que le stockage (le rename final est atomique). */
    public function tempFile(): string
    {
        $dir = $this->root.'/tmp';
        if (!is_dir($dir) && !@mkdir($dir, 0o770, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Impossible de créer %s.', $dir));
        }

        return tempnam($dir, 'blob-');
    }

    /**
     * Range un fichier déjà écrit (il est déplacé). Si $expected est donné, le contenu doit avoir cette empreinte.
     *
     * @return array{sha256: string, size: int}
     */
    public function putFile(string $file, ?string $expected = null): array
    {
        $sha256 = hash_file('sha256', $file);

        return $this->commit($file, $sha256, (int) filesize($file), $expected);
    }

    /** @return array{sha256: string, size: int} */
    public function putString(string $data, ?string $expected = null): array
    {
        $tmp = $this->tempFile();
        file_put_contents($tmp, $data);

        return $this->commit($tmp, hash('sha256', $data), \strlen($data), $expected);
    }

    /**
     * Écrit des morceaux successifs (téléchargement, entrée d'archive…) en calculant l'empreinte au passage.
     *
     * @param iterable<string> $chunks
     *
     * @return array{sha256: string, size: int}
     */
    public function putChunks(iterable $chunks, ?string $expected = null): array
    {
        $tmp = $this->tempFile();
        $out = fopen($tmp, 'w');
        $ctx = hash_init('sha256');
        $size = 0;
        try {
            foreach ($chunks as $chunk) {
                hash_update($ctx, $chunk);
                if (fwrite($out, $chunk) !== \strlen($chunk)) {
                    throw new \RuntimeException('Écriture impossible (disque plein ?).');
                }
                $size += \strlen($chunk);
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);
            throw $e;
        }
        fclose($out);

        return $this->commit($tmp, hash_final($ctx), $size, $expected);
    }

    public function delete(string $sha256): void
    {
        if ($this->has($sha256)) {
            unlink($this->path($sha256));
        }
    }

    /** @return array{sha256: string, size: int} */
    private function commit(string $tmp, string $sha256, int $size, ?string $expected): array
    {
        if (null !== $expected && !hash_equals($expected, $sha256)) {
            @unlink($tmp);
            throw new IntegrityException(sprintf('Empreinte incorrecte : %s attendu, %s obtenu.', $expected, $sha256));
        }

        $target = $this->path($sha256);
        if (is_file($target)) {
            unlink($tmp);
        } else {
            $dir = \dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0o770, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Impossible de créer %s.', $dir));
            }
            chmod($tmp, 0o640);
            rename($tmp, $target);
        }

        return ['sha256' => $sha256, 'size' => $size];
    }
}
