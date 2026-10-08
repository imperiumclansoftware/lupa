<?php

namespace Sas\Bundle;

/**
 * Nommage des bundles sur la clé USB et regroupement des parties d'un même bundle.
 *   sas-<collecteur>-000007.tar            bundle en un seul fichier
 *   sas-<collecteur>-000007.tar.001, .002  bundle découpé
 */
final class BundleFiles
{
    public static function baseName(string $collectorName, int $sequence): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII', $collectorName) ?: $collectorName;
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ascii), '-'));

        return sprintf('sas-%s-%06d', '' === $slug ? 'collecteur' : substr($slug, 0, 40), $sequence);
    }

    /**
     * Bundles présents dans un dossier (non récursif), les parties triées.
     *
     * @return array<string, array{name: string, parts: list<string>, size: int, missingParts: list<int>, modifiedAt: int}>
     */
    public static function discover(string $dir): array
    {
        $bundles = [];
        foreach (new \DirectoryIterator($dir) as $file) {
            if (!$file->isFile() || !preg_match('/^(.+)\.tar(?:\.(\d{3}))?$/', $file->getFilename(), $m)) {
                continue;
            }
            $name = $m[1];
            $bundles[$name] ??= ['name' => $name, 'parts' => [], 'size' => 0, 'missingParts' => [], 'modifiedAt' => 0];
            $bundles[$name]['parts'][(int) ($m[2] ?? 0)] = $file->getPathname();
            $bundles[$name]['size'] += $file->getSize();
            $bundles[$name]['modifiedAt'] = max($bundles[$name]['modifiedAt'], $file->getMTime());
        }

        foreach ($bundles as $name => &$bundle) {
            ksort($bundle['parts']);
            $numbers = array_keys($bundle['parts']);
            if ($numbers !== [0]) {
                // Un .tar seul et des parties numérotées pour le même nom : on garde les parties
                unset($bundle['parts'][0]);
                $numbers = array_keys($bundle['parts']);
                $bundle['missingParts'] = array_values(array_diff(range(1, max($numbers)), $numbers));
            }
            $bundle['parts'] = array_values($bundle['parts']);
        }
        unset($bundle);
        ksort($bundles);

        return $bundles;
    }
}
