<?php

namespace App\Import;

use Sas\Bundle\BundleFiles;

/**
 * Bundles disponibles à l'import : sur la clé USB montée (INBOX_DIR, lecture seule possible)
 * et envoyés depuis le navigateur (UPLOAD_DIR).
 */
final readonly class Inbox
{
    public function __construct(
        private string $inboxDir,
        private string $uploadDir,
    ) {
    }

    /** @return array<string, string> source => dossier */
    public function sources(): array
    {
        return ['usb' => $this->inboxDir, 'upload' => $this->uploadDir];
    }

    /**
     * @return list<array{source: string, name: string, parts: list<string>, size: int, missingParts: list<int>, modifiedAt: int}>
     */
    public function list(): array
    {
        $found = [];
        foreach ($this->sources() as $source => $dir) {
            if (!is_dir($dir) || !is_readable($dir)) {
                continue;
            }
            foreach (BundleFiles::discover($dir) as $bundle) {
                $found[] = ['source' => $source] + $bundle;
            }
        }

        return $found;
    }

    /** @return array{source: string, name: string, parts: list<string>, size: int, missingParts: list<int>, modifiedAt: int}|null */
    public function find(string $source, string $name): ?array
    {
        foreach ($this->list() as $bundle) {
            if ($bundle['source'] === $source && $bundle['name'] === $name) {
                return $bundle;
            }
        }

        return null;
    }

    public function available(string $source): bool
    {
        $dir = $this->sources()[$source] ?? null;

        return null !== $dir && is_dir($dir) && is_readable($dir);
    }
}
