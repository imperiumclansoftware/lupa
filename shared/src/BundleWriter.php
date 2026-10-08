<?php

namespace Sas\Bundle;

use Sas\Bundle\Tar\TarWriter;

/**
 * Produit un bundle : manifest.json et manifest.sig en tête d'archive (le miroir vérifie la signature
 * avant de lire quoi que ce soit d'autre), puis un fichier blobs/<sha256> par blob transporté.
 */
final class BundleWriter
{
    /**
     * @param callable(int $written, int $total): void|null $progress
     *
     * @return list<string> fichiers produits
     */
    public static function write(
        string $basePath,
        Manifest $manifest,
        string $secretKey,
        BlobStore $store,
        ?int $maxPartSize = null,
        ?callable $progress = null,
    ): array {
        $json = $manifest->toJson();
        $tar = new TarWriter($basePath, $maxPartSize);
        $total = $manifest->totalSize();
        $done = 0;
        try {
            $tar->addString('manifest.json', $json);
            $tar->addString('manifest.sig', Signer::sign($json, $secretKey));
            foreach ($manifest->blobs as $blob) {
                if (!$store->has($blob['sha256'])) {
                    throw new IntegrityException(sprintf('Fichier %s absent du stockage.', $blob['sha256']));
                }
                $tar->addFile('blobs/'.$blob['sha256'], $store->path($blob['sha256']));
                $done += $blob['size'];
                if ($progress) {
                    $progress($done, $total);
                }
            }

            return $tar->close();
        } catch (\Throwable $e) {
            $tar->abort();
            throw $e;
        }
    }
}
