<?php

namespace Sas\Bundle;

/** Stockage sur disque : <racine>/sha256/ab/<sha256>, écritures atomiques (rename depuis <racine>/tmp). */
final readonly class LocalBlobBackend implements BlobBackend
{
    public function __construct(private string $root)
    {
    }

    public function name(): string
    {
        return 'local';
    }

    public function describe(): string
    {
        return $this->root;
    }

    public function path(string $key): string
    {
        return $this->root.'/'.$key;
    }

    public function has(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function size(string $key): int
    {
        return (int) filesize($this->path($key));
    }

    public function read(string $key): string
    {
        $data = file_get_contents($this->path($key));
        if (false === $data) {
            throw new \RuntimeException(sprintf('Fichier %s illisible.', $key));
        }

        return $data;
    }

    public function stream(string $key, int $offset = 0, ?int $length = null)
    {
        $handle = @fopen($this->path($key), 'r') ?: throw new \RuntimeException(sprintf('Fichier %s illisible.', $key));
        if ($offset > 0) {
            fseek($handle, $offset);
        }

        return $handle;
    }

    public function store(string $file, string $key, string $sha256): void
    {
        $target = $this->path($key);
        if (is_file($target)) {
            @unlink($file);

            return;
        }
        $dir = \dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0o770, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Impossible de créer %s.', $dir));
        }
        chmod($file, 0o640);
        if (!@rename($file, $target)) {
            // Autre volume (fichier temporaire hors du stockage) : copie puis suppression
            if (!copy($file, $target.'.part') || !rename($target.'.part', $target)) {
                throw new \RuntimeException(sprintf('Impossible d\'écrire %s.', $key));
            }
            @unlink($file);
        }
    }

    public function delete(string $key): void
    {
        if (is_file($path = $this->path($key))) {
            unlink($path);
        }
    }

    public function move(string $from, string $to): void
    {
        $dir = \dirname($this->path($to));
        if (!is_dir($dir) && !@mkdir($dir, 0o770, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Impossible de créer %s.', $dir));
        }
        if (!rename($this->path($from), $this->path($to))) {
            throw new \RuntimeException(sprintf('Impossible de déplacer %s.', $from));
        }
    }

    public function localFile(string $key): array
    {
        return [$this->path($key), false];
    }

    public function keys(string $prefix): iterable
    {
        $base = $this->path(rtrim($prefix, '/'));
        if (!is_dir($base)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && !str_ends_with($file->getFilename(), '.part')) {
                yield ltrim(substr($file->getPathname(), \strlen($this->root)), '/');
            }
        }
    }
}
