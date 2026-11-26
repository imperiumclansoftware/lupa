<?php

namespace Sas\Bundle;

/**
 * Stockage par empreinte : chaque fichier est rangé sous son sha256, une seule copie quel que soit
 * le nombre de paquets ou d'images qui le référencent (les couches Docker communes sont partagées).
 * Le moteur (disque ou S3) est choisi par l'application ; les fichiers temporaires restent sur disque.
 */
final class BlobStore
{
    private ?LocalBlobBackend $local = null;

    /** @param (\Closure(): ?BlobBackend)|null $backend moteur choisi (null : disque, sous $root) */
    public function __construct(
        private readonly string $root,
        private readonly ?\Closure $backend = null,
    ) {
    }

    public static function isDigest(string $sha256): bool
    {
        return 1 === preg_match('/^[a-f0-9]{64}$/', $sha256);
    }

    /** Clé d'un fichier dans le moteur : sha256/ab/<sha256> */
    public static function key(string $sha256): string
    {
        if (!self::isDigest($sha256)) {
            throw new \InvalidArgumentException(sprintf('Empreinte sha256 invalide : "%s".', $sha256));
        }

        return sprintf('sha256/%s/%s', substr($sha256, 0, 2), $sha256);
    }

    public function backend(): BlobBackend
    {
        return (null !== $this->backend ? ($this->backend)() : null) ?? ($this->local ??= new LocalBlobBackend($this->root));
    }

    /** Moteur disque, sous la racine du stockage (source ou destination d'une migration) */
    public function localBackend(): LocalBlobBackend
    {
        return $this->local ??= new LocalBlobBackend($this->root);
    }

    /** Chemin sur disque (moteur local seulement : utiliser withFile() pour un fichier quel que soit le moteur). */
    public function path(string $sha256): string
    {
        $backend = $this->backend();
        if (!$backend instanceof LocalBlobBackend) {
            throw new \LogicException('Stockage S3 : pas de chemin local, utiliser withFile().');
        }

        return $backend->path(self::key($sha256));
    }

    public function has(string $sha256): bool
    {
        return self::isDigest($sha256) && $this->backend()->has(self::key($sha256));
    }

    public function size(string $sha256): int
    {
        return $this->backend()->size(self::key($sha256));
    }

    public function read(string $sha256): string
    {
        return $this->backend()->read(self::key($sha256));
    }

    /**
     * @return resource flux en lecture, depuis $offset
     */
    public function stream(string $sha256, int $offset = 0, ?int $length = null)
    {
        return $this->backend()->stream(self::key($sha256), $offset, $length);
    }

    /**
     * Donne un fichier local à $use (copie temporaire pour un bucket, supprimée ensuite).
     *
     * @template T
     *
     * @param callable(string): T $use
     *
     * @return T
     */
    public function withFile(string $sha256, callable $use): mixed
    {
        [$path, $temporary] = $this->backend()->localFile(self::key($sha256));
        try {
            return $use($path);
        } finally {
            if ($temporary) {
                @unlink($path);
            }
        }
    }

    /** Fichier temporaire dans le même système de fichiers que le stockage disque (le rename final est atomique). */
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
            $this->backend()->delete(self::key($sha256));
        }
    }

    /** Met de côté un fichier abîmé (corrupt/<sha256>-<date>) ; le stockage le considère alors comme absent. */
    public function isolate(string $sha256): ?string
    {
        if (!$this->has($sha256)) {
            return null;
        }
        $target = sprintf('corrupt/%s-%s', $sha256, date('Ymd-His'));
        $this->backend()->move(self::key($sha256), $target);

        return $this->backend()->describe().'/'.$target;
    }

    /** @return array{sha256: string, size: int} */
    private function commit(string $tmp, string $sha256, int $size, ?string $expected): array
    {
        if (null !== $expected && !hash_equals($expected, $sha256)) {
            @unlink($tmp);
            throw new IntegrityException(sprintf('Empreinte incorrecte : %s attendu, %s obtenu.', $expected, $sha256));
        }
        $backend = $this->backend();
        $key = self::key($sha256);
        if ($backend->has($key)) {
            @unlink($tmp);
        } else {
            $backend->store($tmp, $key, $sha256);
        }

        return ['sha256' => $sha256, 'size' => $size];
    }
}
