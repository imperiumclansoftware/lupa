<?php

namespace App\Import;

use Sas\Bundle\BundleFiles;
use Sas\Bundle\ContainerUser;

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
        return $this->scan()['bundles'];
    }

    /**
     * Dossiers qui n'ont pas pu être parcourus : une clé USB qui s'y trouve est invisible.
     *
     * @return list<array{path: string, hint: string|null}>
     */
    public function unreadable(): array
    {
        return $this->scan()['unreadable'];
    }

    /**
     * @return array{bundles: list<array{source: string, name: string, parts: list<string>, size: int, missingParts: list<int>, modifiedAt: int}>, unreadable: list<array{path: string, hint: string|null}>}
     */
    private function scan(): array
    {
        $found = [];
        $unreadable = [];
        foreach ($this->sources() as $source => $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (self::directories($dir, 'usb' === $source ? 2 : 0, $unreadable) as $folder) {
                try {
                    foreach (BundleFiles::discover($folder) as $bundle) {
                        $found[] = ['source' => $source] + $bundle;
                    }
                } catch (\UnexpectedValueException) {
                    $unreadable[] = $folder;
                }
            }
        }

        return [
            'bundles' => $found,
            'unreadable' => array_map(static fn (string $path) => ['path' => $path, 'hint' => ContainerUser::hint($path, 'Romulus')], array_values(array_unique($unreadable))),
        ];
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
     * @param list<string> $unreadable dossiers qu'on n'a pas pu ouvrir (complété)
     *
     * @return list<string>
     */
    private static function directories(string $dir, int $depth, array &$unreadable): array
    {
        if (!is_readable($dir) || !is_executable($dir)) {
            $unreadable[] = $dir;

            return [];
        }
        $dirs = [$dir];
        if ($depth > 0) {
            try {
                foreach (new \DirectoryIterator($dir) as $entry) {
                    if ($entry->isDir() && !$entry->isDot() && !str_starts_with($entry->getFilename(), '.')) {
                        array_push($dirs, ...self::directories($entry->getPathname(), $depth - 1, $unreadable));
                    }
                }
            } catch (\UnexpectedValueException) {
                $unreadable[] = $dir;
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
