<?php

namespace App\Tests\Support;

use Sas\Bundle\BlobStore;
use Sas\Bundle\BundleFiles;
use Sas\Bundle\BundleWriter;
use Sas\Bundle\Manifest;

/**
 * Construit un vrai bundle signé, comme le ferait le collecteur : les fichiers sont rangés dans un
 * stockage source temporaire puis écrits avec BundleWriter.
 */
final class BundleBuilder
{
    private BlobStore $store;
    /** @var list<array<string, mixed>> */
    private array $artifacts = [];
    /** @var array<string, array{sha256: string, size: int}> */
    private array $blobs = [];

    public function __construct(string $sourceDir, private readonly string $collectorName = 'Collecteur test')
    {
        $this->store = new BlobStore($sourceDir);
    }

    /** Ajoute un fichier transporté par le bundle ; renvoie son sha256. */
    public function blob(string $data): string
    {
        $blob = $this->store->putString($data);
        $this->blobs[$blob['sha256']] = $blob;

        return $blob['sha256'];
    }

    /**
     * @param list<string>         $blobs    empreintes (transportées ou non par ce bundle)
     * @param array<string, mixed> $metadata
     */
    public function artifact(string $ecosystem, string $name, string $version, ?string $filename, array $blobs, array $metadata = []): self
    {
        $this->artifacts[] = ['ecosystem' => $ecosystem, 'name' => $name, 'version' => $version, 'filename' => $filename, 'blobs' => $blobs, 'metadata' => $metadata];

        return $this;
    }

    /**
     * Écrit le bundle dans $dir ; $signingKey permet de signer avec une autre clé que celle annoncée.
     *
     * @param array{secretKey: string, publicKey: string} $keys
     *
     * @return string nom du bundle (sans .tar)
     */
    public function write(string $dir, array $keys, int $sequence = 1, bool $full = true, ?string $signingKey = null): string
    {
        $manifest = new Manifest(
            self::uuid(),
            'collector-test',
            $this->collectorName,
            $keys['publicKey'],
            $sequence,
            new \DateTimeImmutable(),
            $full,
            $this->artifacts,
            array_values($this->blobs),
        );
        $name = BundleFiles::baseName($this->collectorName, $sequence);
        BundleWriter::write($dir.'/'.$name, $manifest, $signingKey ?? $keys['secretKey'], $this->store);

        return $name;
    }

    private static function uuid(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }
}
