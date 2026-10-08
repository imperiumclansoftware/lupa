<?php

namespace Sas\Bundle;

use Sas\Bundle\Tar\TarEntry;
use Sas\Bundle\Tar\TarReader;

/**
 * Lit un bundle en deux temps : d'abord le manifeste et sa signature (sans rien écrire),
 * puis, une fois la signature acceptée, les blobs un par un.
 */
final class BundleReader
{
    private TarReader $tar;
    /** @var \Generator<int, TarEntry> */
    private \Generator $entries;
    private ?Manifest $manifest = null;
    private string $rawManifest = '';
    private string $signature = '';

    /** @param list<string> $parts */
    public function __construct(array $parts)
    {
        $this->tar = new TarReader($parts);
        $this->entries = $this->tar->entries();
    }

    public function manifest(): Manifest
    {
        if ($this->manifest) {
            return $this->manifest;
        }
        $this->rawManifest = $this->take('manifest.json', 64 << 20);
        $this->signature = $this->take('manifest.sig', 4096);

        return $this->manifest = Manifest::fromJson($this->rawManifest);
    }

    public function verifySignature(string $publicKey): bool
    {
        $this->manifest();

        return Signer::verify($this->rawManifest, $this->signature, $publicKey);
    }

    /**
     * Copie dans le stockage les blobs du bundle, en vérifiant chaque empreinte.
     *
     * @param callable(string $sha256, int $position): void|null $progress appelé après chaque blob
     *
     * @return list<string> empreintes importées
     */
    public function extractBlobs(BlobStore $store, ?callable $progress = null): array
    {
        $manifest = $this->manifest();
        $expected = [];
        foreach ($manifest->blobs as $blob) {
            $expected[$blob['sha256']] = $blob['size'];
        }

        $seen = [];
        while ($this->entries->valid()) {
            $entry = $this->entries->current();
            if (!preg_match('#^blobs/([a-f0-9]{64})$#', $entry->name, $m) || !isset($expected[$m[1]])) {
                throw new IntegrityException(sprintf('Fichier inattendu dans le bundle : %s.', $entry->name));
            }
            $sha = $m[1];
            if ($entry->size !== $expected[$sha]) {
                throw new IntegrityException(sprintf('Taille incorrecte pour %s.', $sha));
            }
            if ($store->has($sha)) {
                // Déjà présent (bundle réimporté ou blob partagé) : on le lit quand même pour avancer
                foreach ($entry->chunks() as $_) {
                }
            } else {
                $store->putChunks($entry->chunks(), $sha);
            }
            $seen[$sha] = true;
            $this->entries->next();
            if ($progress) {
                $progress($sha, $this->tar->position());
            }
        }

        $missing = array_diff_key($expected, $seen);
        if ([] !== $missing) {
            throw new IntegrityException(sprintf('%d fichier(s) annoncé(s) par le manifeste absent(s) de l\'archive.', \count($missing)));
        }

        return array_keys($seen);
    }

    private function take(string $name, int $maxSize): string
    {
        if (!$this->entries->valid()) {
            throw new IntegrityException('Bundle vide.');
        }
        $entry = $this->entries->current();
        if ($entry->name !== $name) {
            throw new IntegrityException(sprintf('Ce fichier n\'est pas un bundle Sas (%s attendu, %s trouvé).', $name, $entry->name));
        }
        $contents = $entry->contents($maxSize);
        $this->entries->next();

        return $contents;
    }
}
