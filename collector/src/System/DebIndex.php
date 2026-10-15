<?php

namespace App\System;

use App\Job\JobContext;
use Sas\Bundle\Distributions;

/** Télécharge, vérifie (InRelease signé) et analyse les index Packages d'une distribution Debian. */
final readonly class DebIndex
{
    private const int FORMAT = 1;

    public function __construct(
        private IndexFiles $files,
        private Signatures $signatures,
    ) {
    }

    /** @param list<string> $sections main, contrib… */
    public function load(string $distribution, string $arch, array $sections, JobContext $context): DebRepository
    {
        $dist = Distributions::find($distribution) ?? throw new \InvalidArgumentException(sprintf('Distribution inconnue : %s.', $distribution));
        $repository = new DebRepository();
        foreach ($dist['suites'] as $suite) {
            $base = $suite['url'];
            $release = $this->signatures->clearsigned(
                (string) $this->files->text(sprintf('%s/dists/%s/InRelease', $base, $suite['suite'])),
                $suite['keys'],
                $suite['suite'],
            );
            $hashes = self::sha256($release);
            foreach ($sections as $section) {
                $found = null;
                foreach (["$section/binary-$arch/Packages.xz", "updates/$section/binary-$arch/Packages.xz", "$section/binary-$arch/Packages.gz"] as $path) {
                    if (isset($hashes[$path])) {
                        $found = $path;
                        break;
                    }
                }
                if (null === $found) {
                    continue;
                }
                $file = $this->files->plain(sprintf('%s/dists/%s/%s', $base, $suite['suite'], $found), 'sha256', $hashes[$found], $context, $suite['suite'].' '.$section.' '.$arch);
                $source = \count($repository->sources);
                $repository->sources[] = ['base' => $base, 'file' => $file, 'suite' => $suite['suite']];
                $repository->add($source, $this->parse($file, $section));
            }
        }
        if ([] === $repository->packages) {
            throw new \RuntimeException(sprintf('Aucun paquet trouvé pour %s (%s).', $dist['label'], $arch));
        }

        return $repository;
    }

    /** @return array<string, string> chemin => sha256 (section SHA256 du fichier Release) */
    public static function sha256(string $release): array
    {
        $hashes = [];
        if (preg_match('/^SHA256:\R((?:[ \t]+\S+[ \t]+\d+[ \t]+\S+\R?)+)/m', $release, $m)) {
            foreach (preg_split('/\R/', trim($m[1])) ?: [] as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (3 === \count($parts)) {
                    $hashes[$parts[2]] = strtolower($parts[0]);
                }
            }
        }

        return $hashes;
    }

    /**
     * Champs utiles de chaque paquet, avec la position de son texte dans le fichier (cache à côté).
     *
     * @return list<array<string, mixed>>
     */
    public function parse(string $file, string $section): array
    {
        $cache = $file.'.idx'.self::FORMAT;
        if (is_file($cache) && false !== $data = @unserialize((string) file_get_contents($cache))) {
            return array_map(static fn (array $e) => $e + ['c' => $section], $data);
        }
        $entries = [];
        $handle = fopen($file, 'r');
        $offset = 0;
        $fields = [];
        $start = 0;
        $last = null;
        $flush = static function (int $end) use (&$fields, &$start, &$entries): void {
            if (isset($fields['package'], $fields['version'], $fields['filename'], $fields['sha256'])) {
                $entries[] = [
                    'n' => strtolower($fields['package']),
                    'v' => $fields['version'],
                    'a' => $fields['architecture'] ?? 'all',
                    'dep' => trim(($fields['pre-depends'] ?? '').','.($fields['depends'] ?? ''), ','),
                    'rec' => $fields['recommends'] ?? '',
                    'prov' => $fields['provides'] ?? '',
                    'f' => $fields['filename'],
                    's' => (int) ($fields['size'] ?? 0),
                    'h' => strtolower($fields['sha256']),
                    'o' => $start,
                    'l' => $end - $start,
                    'd' => mb_substr($fields['description'] ?? '', 0, 160),
                ];
            }
            $fields = [];
        };
        while (false !== $line = fgets($handle)) {
            $length = \strlen($line);
            if ("\n" === $line || "\r\n" === $line) {
                $flush($offset);
                $start = $offset + $length;
            } elseif (' ' === $line[0] || "\t" === $line[0]) {
                // Suite d'un champ sur plusieurs lignes (Description…) : inutile à l'index
            } elseif (false !== $colon = strpos($line, ':')) {
                $last = strtolower(substr($line, 0, $colon));
                $fields[$last] = trim(substr($line, $colon + 1));
            }
            $offset += $length;
        }
        $flush($offset);
        fclose($handle);
        @file_put_contents($cache, serialize($entries));

        return array_map(static fn (array $e) => $e + ['c' => $section], $entries);
    }
}
