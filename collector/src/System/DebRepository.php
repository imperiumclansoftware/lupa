<?php

namespace App\System;

use Sas\Bundle\DebVersion;

/**
 * Index d'une distribution Debian pour une architecture (toutes suites et sections confondues).
 * Les champs complets d'un paquet sont relus dans le fichier Packages au moment de l'exporter.
 */
final class DebRepository
{
    /** @var list<array{n: string, v: string, a: string, dep: string, rec: string, prov: string, f: string, s: int, h: string, o: int, l: int, src: int, c: string, d: string}> */
    public array $packages = [];

    /** @var array<string, list<int>> */
    private array $byName = [];

    /** @var array<string, list<array{int, string|null}>> paquet virtuel => [fournisseur, version fournie] */
    private array $provides = [];

    /** @param list<array{base: string, file: string, suite: string}> $sources */
    public function __construct(public array $sources = [])
    {
    }

    /** @param list<array<string, mixed>> $entries */
    public function add(int $source, array $entries): void
    {
        foreach ($entries as $entry) {
            $entry['src'] = $source;
            $id = \count($this->packages);
            $this->packages[] = $entry;
            $this->byName[$entry['n']][] = $id;
            foreach (self::parseList($entry['prov']) as [$name, , $version]) {
                $this->provides[$name][] = [$id, $version];
            }
        }
    }

    /** @return list<int> versions d'un paquet réel, la plus récente d'abord */
    public function versions(string $name): array
    {
        $ids = $this->byName[$name] ?? [];
        usort($ids, fn (int $a, int $b) => DebVersion::compare($this->packages[$b]['v'], $this->packages[$a]['v']));

        return $ids;
    }

    /** @return list<array{int, string|null}> */
    public function providers(string $name): array
    {
        return $this->provides[$name] ?? [];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->byName);
    }

    /** Champs complets du paquet (texte du fichier Packages). */
    public function stanza(int $id): string
    {
        $entry = $this->packages[$id];
        $handle = fopen($this->sources[$entry['src']]['file'], 'r');
        fseek($handle, $entry['o']);
        $text = (string) fread($handle, $entry['l']);
        fclose($handle);

        return rtrim($text)."\n";
    }

    /**
     * « a (>= 1), b | c:any » → [[[a, >=, 1]], [[b, null, null], [c, null, null]]]
     *
     * @return list<list<array{string, string|null, string|null}>>
     */
    public static function parseDepends(string $field): array
    {
        $clauses = [];
        foreach (array_filter(array_map('trim', explode(',', $field))) as $clause) {
            $clauses[] = self::parseList(str_replace('|', ',', $clause));
        }

        return $clauses;
    }

    /** @return list<array{string, string|null, string|null}> */
    private static function parseList(string $field): array
    {
        $items = [];
        foreach (array_filter(array_map('trim', explode(',', $field))) as $item) {
            if (preg_match('/^([a-z0-9][a-z0-9+.\-]*)(?::[a-z0-9-]+)?\s*(?:\(\s*(<<|<=|=|>=|>>|<|>)\s*([^)\s]+)\s*\))?/i', $item, $m)) {
                $items[] = [strtolower($m[1]), $m[2] ?? null ?: null, $m[3] ?? null ?: null];
            }
        }

        return $items;
    }
}
