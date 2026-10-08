<?php

namespace App\Import;

use Sas\Bundle\BundleFiles;

/**
 * Bundles disponibles à l'import : sur la clé USB montée (INBOX_DIR et deux niveaux de sous-dossiers, lecture seule possible)
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
            foreach (self::directories($dir, 'usb' === $source ? 2 : 0) as $folder) {
                foreach (BundleFiles::discover($folder) as $bundle) {
                    $found[] = ['source' => $source] + $bundle;
                }
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

    /**
     * Le dossier et ses sous-dossiers jusqu'à $depth niveaux : en montant /media, la clé apparaît
     * sous /media/<utilisateur>/<nom de la clé> sans avoir à connaître son nom.
     *
     * @return list<string>
     */
    private static function directories(string $dir, int $depth): array
    {
        $dirs = [$dir];
        if ($depth > 0) {
            foreach (new \DirectoryIterator($dir) as $entry) {
                if ($entry->isDir() && !$entry->isDot() && !str_starts_with($entry->getFilename(), '.') && $entry->isReadable()) {
                    array_push($dirs, ...self::directories($entry->getPathname(), $depth - 1));
                }
            }
        }

        return $dirs;
    }

    public function available(string $source): bool
    {
        $dir = $this->sources()[$source] ?? null;

        return null !== $dir && is_dir($dir) && is_readable($dir);
    }
}
