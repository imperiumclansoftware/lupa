<?php

namespace App\Search;

/**
 * Motifs de noms de paquets : « * » = n'importe quelle suite de caractères, « ? » = un caractère.
 * Ex. @syncfusion/ej2-*angular*, symfony/*-bundle, django-*, node:24-*alpine
 */
final class Glob
{
    public static function isPattern(string $value): bool
    {
        return str_contains($value, '*') || str_contains($value, '?');
    }

    public static function regex(string $pattern): string
    {
        $parts = array_map(static fn (string $part) => preg_quote($part, '/'), preg_split('/([*?])/', $pattern, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: []);

        return '/^'.strtr(implode('', $parts), ['\\*' => '.*', '\\?' => '.']).'$/i';
    }

    public static function matches(string $pattern, string $value): bool
    {
        return 1 === preg_match(self::regex($pattern), $value);
    }

    /**
     * Fragment le plus sélectif pour filtrer côté registre : le début du motif s'il en a un
     * (« 24- » pour 24-*alpine), sinon le plus long fragment.
     */
    public static function filter(string $pattern): ?string
    {
        $prefix = preg_split('/[*?]/', $pattern)[0] ?? '';

        return '' !== trim($prefix, '-_./@:') ? $prefix : (self::literals($pattern)[0] ?? null);
    }

    /** Fragments littéraux du motif (pour interroger un moteur de recherche), du plus long au plus court. */
    public static function literals(string $pattern): array
    {
        $parts = array_values(array_filter(preg_split('/[*?]+/', $pattern) ?: [], static fn (string $p) => '' !== trim($p, '-_./@')));
        usort($parts, static fn (string $a, string $b) => \strlen($b) <=> \strlen($a));

        return $parts;
    }
}
