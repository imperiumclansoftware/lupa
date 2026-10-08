<?php

namespace Sas\Bundle\Tar;

final class TarEntry
{
    public function __construct(
        public readonly string $name,
        public readonly int $size,
        private readonly TarReader $reader,
    ) {
    }

    /** Contenu complet ; réservé aux petites entrées (manifeste, signature). */
    public function contents(int $maxSize = 64 << 20): string
    {
        if ($this->size > $maxSize) {
            throw new \RuntimeException(sprintf('Entrée %s trop volumineuse (%d octets).', $this->name, $this->size));
        }

        return implode('', iterator_to_array($this->chunks(), false));
    }

    /** @return \Generator<int, string> contenu par morceaux de 1 Mio au plus */
    public function chunks(): \Generator
    {
        while ('' !== ($chunk = $this->reader->readEntry(1 << 20))) {
            yield $chunk;
        }
    }
}
