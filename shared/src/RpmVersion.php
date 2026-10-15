<?php

namespace Sas\Bundle;

/** Comparaison de versions RPM (rpmvercmp) sur [epoch:]version[-release]. */
final class RpmVersion
{
    /** @return array{int, string, string|null} epoch, version, release (null : non précisée) */
    public static function parse(string $evr): array
    {
        $epoch = 0;
        if (false !== $colon = strpos($evr, ':')) {
            $epoch = (int) substr($evr, 0, $colon);
            $evr = substr($evr, $colon + 1);
        }
        $dash = strrpos($evr, '-');

        return false === $dash ? [$epoch, $evr, null] : [$epoch, substr($evr, 0, $dash), substr($evr, $dash + 1)];
    }

    /** Écriture canonique : époque omise si nulle. */
    public static function format(int $epoch, string $version, ?string $release): string
    {
        return ($epoch > 0 ? $epoch.':' : '').$version.(null !== $release && '' !== $release ? '-'.$release : '');
    }

    /** Compare deux EVR ; une release absente d'un côté n'est pas comparée (comme rpm pour « foo >= 1.2 »). */
    public static function compare(string $a, string $b): int
    {
        [$ea, $va, $ra] = self::parse($a);
        [$eb, $vb, $rb] = self::parse($b);

        return ($ea <=> $eb) ?: self::vercmp($va, $vb) ?: (null === $ra || null === $rb ? 0 : self::vercmp($ra, $rb));
    }

    /** Flags de primary.xml : EQ, LT, LE, GT, GE. */
    public static function satisfies(string $evr, string $flags, string $wanted): bool
    {
        $c = self::compare($evr, $wanted);

        return match ($flags) {
            'EQ' => 0 === $c,
            'LT' => $c < 0,
            'LE' => $c <= 0,
            'GT' => $c > 0,
            'GE' => $c >= 0,
            default => true,
        };
    }

    public static function vercmp(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        $i = $j = 0;
        $la = \strlen($a);
        $lb = \strlen($b);
        while ($i < $la || $j < $lb) {
            while ($i < $la && !ctype_alnum($a[$i]) && '~' !== $a[$i] && '^' !== $a[$i]) {
                ++$i;
            }
            while ($j < $lb && !ctype_alnum($b[$j]) && '~' !== $b[$j] && '^' !== $b[$j]) {
                ++$j;
            }
            // « ~ » : antérieur à tout, même à la fin de chaîne
            $ta = $i < $la && '~' === $a[$i];
            $tb = $j < $lb && '~' === $b[$j];
            if ($ta || $tb) {
                if (!$ta) {
                    return 1;
                }
                if (!$tb) {
                    return -1;
                }
                ++$i;
                ++$j;
                continue;
            }
            // « ^ » : postérieur à la fin de chaîne, antérieur à tout le reste
            $ca = $i < $la && '^' === $a[$i];
            $cb = $j < $lb && '^' === $b[$j];
            if ($ca || $cb) {
                if ($i >= $la) {
                    return -1;
                }
                if ($j >= $lb) {
                    return 1;
                }
                if (!$ca) {
                    return 1;
                }
                if (!$cb) {
                    return -1;
                }
                ++$i;
                ++$j;
                continue;
            }
            if ($i >= $la || $j >= $lb) {
                break;
            }
            $numeric = ctype_digit($a[$i]);
            $sa = $sb = '';
            if ($numeric) {
                while ($i < $la && ctype_digit($a[$i])) {
                    $sa .= $a[$i++];
                }
                while ($j < $lb && ctype_digit($b[$j])) {
                    $sb .= $b[$j++];
                }
                if ('' === $sb) {
                    return 1;
                }
                $sa = ltrim($sa, '0');
                $sb = ltrim($sb, '0');
                if ($c = (\strlen($sa) <=> \strlen($sb)) ?: (strcmp($sa, $sb) <=> 0)) {
                    return $c;
                }
            } else {
                while ($i < $la && ctype_alpha($a[$i])) {
                    $sa .= $a[$i++];
                }
                while ($j < $lb && ctype_alpha($b[$j])) {
                    $sb .= $b[$j++];
                }
                if ('' === $sb) {
                    return -1;
                }
                if ($c = strcmp($sa, $sb) <=> 0) {
                    return $c;
                }
            }
        }

        return ($i < $la ? 1 : 0) - ($j < $lb ? 1 : 0);
    }
}
