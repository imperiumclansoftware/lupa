<?php

namespace Sas\Bundle\Tar;

/**
 * Écrit une archive tar (ustar) en flux, sans compression : les paquets sont déjà compressés.
 *
 * Avec $maxPartSize, l'archive est découpée en parties de taille fixe (nom.tar.001, nom.tar.002…)
 * pour tenir sur une clé formatée en FAT32 (fichiers de 4 Go au plus). Les parties mises bout à bout
 * redonnent l'archive : `cat nom.tar.* > nom.tar` fonctionne aussi.
 *
 * Les fichiers portent le suffixe .tmp tant que l'archive n'est pas terminée.
 */
final class TarWriter
{
    private const int BLOCK = 512;

    /** @var resource|null */
    private $handle;
    private int $partWritten = 0;
    /** @var list<string> */
    private array $parts = [];
    private int $total = 0;
    private bool $closed = false;

    public function __construct(
        private readonly string $basePath,
        private readonly ?int $maxPartSize = null,
    ) {
        if (null !== $maxPartSize && ($maxPartSize < self::BLOCK || 0 !== $maxPartSize % self::BLOCK)) {
            throw new \InvalidArgumentException('La taille des parties doit être un multiple de 512 octets.');
        }
    }

    public function addString(string $name, string $data): void
    {
        $this->write(self::header($name, \strlen($data)));
        $this->write($data);
        $this->pad(\strlen($data));
    }

    public function addFile(string $name, string $path): void
    {
        $size = filesize($path);
        $in = fopen($path, 'r');
        if (false === $size || false === $in) {
            throw new \RuntimeException(sprintf('Fichier %s illisible.', $path));
        }
        $this->write(self::header($name, $size));
        $copied = 0;
        while ($copied < $size && '' !== ($chunk = (string) fread($in, 1 << 20))) {
            $this->write($chunk);
            $copied += \strlen($chunk);
        }
        fclose($in);
        if ($copied !== $size) {
            throw new \RuntimeException(sprintf('Fichier %s modifié pendant l\'écriture de l\'archive.', $path));
        }
        $this->pad($size);
    }

    public function bytesWritten(): int
    {
        return $this->total;
    }

    /** @return list<string> chemins des fichiers produits, dans l'ordre */
    public function close(): array
    {
        if ($this->closed) {
            throw new \LogicException('Archive déjà fermée.');
        }
        // Fin d'archive : deux blocs vides
        $this->write(str_repeat("\0", self::BLOCK * 2));
        if ($this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
        $this->closed = true;

        $final = [];
        $single = 1 === \count($this->parts);
        foreach ($this->parts as $i => $tmp) {
            $name = $single ? $this->basePath.'.tar' : sprintf('%s.tar.%03d', $this->basePath, $i + 1);
            rename($tmp, $name);
            $final[] = $name;
        }

        return $final;
    }

    /** Supprime les fichiers en cours d'écriture (export annulé ou en échec). */
    public function abort(): void
    {
        if ($this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
        foreach ($this->parts as $tmp) {
            @unlink($tmp);
        }
        $this->closed = true;
    }

    private function pad(int $size): void
    {
        $rest = $size % self::BLOCK;
        if (0 !== $rest) {
            $this->write(str_repeat("\0", self::BLOCK - $rest));
        }
    }

    private function write(string $bytes): void
    {
        while ('' !== $bytes) {
            if (null === $this->handle || (null !== $this->maxPartSize && $this->partWritten >= $this->maxPartSize)) {
                $this->nextPart();
            }
            $room = null === $this->maxPartSize ? \strlen($bytes) : $this->maxPartSize - $this->partWritten;
            $chunk = substr($bytes, 0, $room);
            if (fwrite($this->handle, $chunk) !== \strlen($chunk)) {
                throw new \RuntimeException('Écriture impossible (support plein ?).');
            }
            $this->partWritten += \strlen($chunk);
            $this->total += \strlen($chunk);
            $bytes = (string) substr($bytes, \strlen($chunk));
        }
    }

    private function nextPart(): void
    {
        if ($this->handle) {
            fclose($this->handle);
        }
        $tmp = sprintf('%s.tar.%03d.tmp', $this->basePath, \count($this->parts) + 1);
        $handle = fopen($tmp, 'x');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Impossible de créer %s.', $tmp));
        }
        $this->handle = $handle;
        $this->parts[] = $tmp;
        $this->partWritten = 0;
    }

    private static function header(string $name, int $size): string
    {
        if (\strlen($name) > 100 || str_contains($name, '..')) {
            throw new \InvalidArgumentException(sprintf('Nom d\'entrée invalide : "%s".', $name));
        }

        // Taille : octal sur 11 chiffres jusqu'à 8 Gio, encodage binaire GNU (base 256) au-delà
        if ($size < 8 ** 11) {
            $sizeField = sprintf('%011o', $size)."\0";
        } else {
            $sizeField = "\x80".str_repeat("\0", 3).pack('J', $size);
        }

        $header = str_pad($name, 100, "\0")
            .sprintf('%07o', 0o644)."\0"
            .sprintf('%07o', 0)."\0"
            .sprintf('%07o', 0)."\0"
            .$sizeField
            .sprintf('%011o', time())."\0"
            .'        '
            .'0'
            .str_repeat("\0", 100)
            ."ustar\0".'00'
            .str_pad('sas', 32, "\0")
            .str_pad('sas', 32, "\0")
            .str_repeat("\0", 8 + 8 + 155 + 12);

        $sum = array_sum(array_map(ord(...), str_split($header)));

        return substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);
    }
}
