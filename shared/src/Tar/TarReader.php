<?php

namespace Sas\Bundle\Tar;

use Sas\Bundle\IntegrityException;

/**
 * Lit en flux une archive tar, éventuellement découpée en plusieurs parties lues l'une après l'autre.
 * Chaque entrée doit être lue (ou ignorée) avant de passer à la suivante : rien n'est chargé en mémoire.
 */
final class TarReader
{
    private const int BLOCK = 512;

    /** @var resource|null */
    private $handle;
    private int $partIndex = -1;
    private int $remaining = 0;
    private int $padding = 0;
    private int $position = 0;

    /** @param list<string> $parts */
    public function __construct(private readonly array $parts)
    {
        if ([] === $parts) {
            throw new \InvalidArgumentException('Aucun fichier à lire.');
        }
    }

    /** @return \Generator<int, TarEntry> */
    public function entries(): \Generator
    {
        try {
            while (true) {
                $this->skip($this->remaining + $this->padding);
                $this->remaining = $this->padding = 0;

                $header = $this->readExact(self::BLOCK, true);
                if (null === $header || $header === str_repeat("\0", self::BLOCK)) {
                    return;
                }
                [$name, $size, $type] = self::parseHeader($header);

                $this->remaining = $size;
                $this->padding = (self::BLOCK - $size % self::BLOCK) % self::BLOCK;

                // Seuls les fichiers ordinaires nous intéressent (pas de liens ni d'en-têtes étendus)
                if ('0' === $type || "\0" === $type) {
                    yield new TarEntry($name, $size, $this);
                }
            }
        } finally {
            if ($this->handle) {
                fclose($this->handle);
                $this->handle = null;
            }
        }
    }

    /** Position dans l'archive complète (toutes parties confondues), pour suivre la progression. */
    public function position(): int
    {
        return $this->position;
    }

    /** @internal lit au plus $length octets de l'entrée courante ; '' en fin d'entrée */
    public function readEntry(int $length): string
    {
        $length = min($length, $this->remaining);
        if (0 === $length) {
            return '';
        }
        $data = $this->readExact($length);
        $this->remaining -= $length;

        return $data;
    }

    private function skip(int $length): void
    {
        while ($length > 0) {
            $n = min($length, 1 << 20);
            $this->readExact($n);
            $length -= $n;
        }
    }

    /** @return ($allowEof is true ? string|null : string) */
    private function readExact(int $length, bool $allowEof = false): ?string
    {
        $data = '';
        while (\strlen($data) < $length) {
            if (null === $this->handle || feof($this->handle)) {
                if (!$this->openNextPart()) {
                    if ($allowEof && '' === $data) {
                        return null;
                    }
                    throw new IntegrityException(sprintf(
                        'Archive incomplète après %s (%d partie(s) lue(s)) : une partie manque ou a été tronquée pendant la copie.',
                        basename($this->parts[\count($this->parts) - 1]),
                        \count($this->parts),
                    ));
                }
                continue;
            }
            $chunk = fread($this->handle, $length - \strlen($data));
            if (false === $chunk) {
                throw new \RuntimeException('Lecture impossible.');
            }
            $data .= $chunk;
        }
        $this->position += $length;

        return $data;
    }

    private function openNextPart(): bool
    {
        if ($this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
        if (!isset($this->parts[$this->partIndex + 1])) {
            return false;
        }
        ++$this->partIndex;
        $handle = fopen($this->parts[$this->partIndex], 'r');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Fichier %s illisible.', $this->parts[$this->partIndex]));
        }
        $this->handle = $handle;

        return true;
    }

    /** @return array{string, int, string} */
    private static function parseHeader(string $header): array
    {
        $stored = octdec(trim(substr($header, 148, 8), " \0"));
        $sum = array_sum(array_map(ord(...), str_split(substr_replace($header, '        ', 148, 8))));
        if ($stored !== $sum) {
            throw new IntegrityException('En-tête tar corrompu : ce fichier n\'est pas un bundle Sas valide ou il a été endommagé.');
        }

        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = rtrim(substr($header, 345, 155), "\0");
        if ('' !== $prefix && 'ustar' === substr($header, 257, 5)) {
            $name = $prefix.'/'.$name;
        }

        $sizeField = substr($header, 124, 12);
        if (\ord($sizeField[0]) & 0x80) {
            $size = unpack('J', substr($sizeField, 4, 8))[1];
        } else {
            $size = (int) octdec(trim($sizeField, " \0"));
        }

        return [$name, $size, $header[156]];
    }
}
