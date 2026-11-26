<?php

namespace Sas\Bundle;

/**
 * Copie des fichiers d'un moteur à l'autre (disque vers S3, ou retour), empreinte de chaque fichier vérifiée
 * avant envoi. Reprend là où elle s'est arrêtée : un fichier déjà présent de même taille n'est pas recopié.
 * Les fichiers d'origine sont conservés.
 */
final readonly class BlobMigration
{
    /**
     * @param callable(int $done, int $total, string $step): void $progress
     * @param callable(string): void                              $log
     *
     * @return array{copied: int, skipped: int, failed: int, bytes: int}
     */
    public function run(BlobBackend $from, BlobBackend $to, string $tempDir, callable $progress, callable $log): array
    {
        $keys = [];
        foreach ($from->keys('sha256') as $key) {
            if (preg_match('#^sha256/[a-f0-9]{2}/([a-f0-9]{64})$#', $key)) {
                $keys[] = $key;
            }
        }
        $stats = ['copied' => 0, 'skipped' => 0, 'failed' => 0, 'bytes' => 0];
        foreach ($keys as $index => $key) {
            $progress($index, \count($keys), sprintf('Copie des fichiers (%d/%d)', $index + 1, \count($keys)));
            $sha256 = substr($key, -64);
            try {
                $size = $from->size($key);
                if ($to->has($key) && $to->size($key) === $size) {
                    ++$stats['skipped'];
                    continue;
                }
                [$path, $temporary] = $from->localFile($key);
                try {
                    if (!hash_equals($sha256, (string) hash_file('sha256', $path))) {
                        throw new IntegrityException('empreinte incorrecte à la source (lancez la vérification d\'intégrité)');
                    }
                    // Le moteur cible consomme le fichier : on lui en donne une copie si l'original doit rester
                    $copy = $temporary ? $path : $this->copy($path, $tempDir);
                    $to->store($copy, $key, $sha256);
                } finally {
                    if ($temporary) {
                        @unlink($path);
                    }
                }
                ++$stats['copied'];
                $stats['bytes'] += $size;
            } catch (\Throwable $e) {
                ++$stats['failed'];
                $log(sprintf('ERREUR %s : %s', $sha256, $e->getMessage()));
            }
        }
        $progress(\count($keys), \count($keys), 'Copie terminée');

        return $stats;
    }

    private function copy(string $path, string $tempDir): string
    {
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0o770, true) && !is_dir($tempDir)) {
            throw new \RuntimeException(sprintf('Impossible de créer %s.', $tempDir));
        }
        $copy = tempnam($tempDir, 'migrate-');
        if (!copy($path, $copy)) {
            throw new \RuntimeException('Copie temporaire impossible (disque plein ?).');
        }

        return $copy;
    }
}
