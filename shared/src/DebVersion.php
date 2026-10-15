<?php

namespace Sas\Bundle;

/** Comparaison de versions Debian, comme dpkg --compare-versions ([epoch:]upstream[-revision]). */
final class DebVersion
{
    public static function compare(string $a, string $b): int
    {
        [$ea, $ua, $ra] = self::split($a);
        [$eb, $ub, $rb] = self::split($b);

        return ($ea <=> $eb) ?: self::compareParts($ua, $ub) ?: self::compareParts($ra, $rb);
    }

    /** Vérifie « v1 op v2 » avec les opérateurs de Depends (<<, <=, =, >=, >>). */
    public static function satisfies(string $version, string $op, string $wanted): bool
    {
        $c = self::compare($version, $wanted);

        return match ($op) {
            '<<', '<' => $c < 0,
            '<=' => $c <= 0,
            '=' => 0 === $c,
            '>=' => $c >= 0,
            '>>', '>' => $c > 0,
            default => true,
        };
    }

    /** @return array{int, string, string} */
    private static function split(string $v): array
    {
        $epoch = 0;
        if (false !== $colon = strpos($v, ':')) {
            $epoch = (int) substr($v, 0, $colon);
            $v = substr($v, $colon + 1);
        }
        $dash = strrpos($v, '-');

        return false === $dash ? [$epoch, $v, '0'] : [$epoch, substr($v, 0, $dash), substr($v, $dash + 1)];
    }

    /** Algorithme de dpkg : alternance de parties non numériques (ordre « ~ » < fin < lettres < autres) et numériques. */
    private static function compareParts(string $a, string $b): int
    {
        $i = $j = 0;
        $la = \strlen($a);
        $lb = \strlen($b);
        while ($i < $la || $j < $lb) {
            while (($i < $la && !ctype_digit($a[$i])) || ($j < $lb && !ctype_digit($b[$j]))) {
                $ca = $i < $la && !ctype_digit($a[$i]) ? self::order($a[$i]) : 0;
                $cb = $j < $lb && !ctype_digit($b[$j]) ? self::order($b[$j]) : 0;
                if ($ca !== $cb) {
                    return $ca <=> $cb;
                }
                if ($i < $la && !ctype_digit($a[$i])) {
                    ++$i;
                }
                if ($j < $lb && !ctype_digit($b[$j])) {
                    ++$j;
                }
            }
            $na = '';
            while ($i < $la && ctype_digit($a[$i])) {
                $na .= $a[$i++];
            }
            $nb = '';
            while ($j < $lb && ctype_digit($b[$j])) {
                $nb .= $b[$j++];
            }
            $na = ltrim($na, '0');
            $nb = ltrim($nb, '0');
            if ($na !== $nb) {
                return \strlen($na) <=> \strlen($nb) ?: strcmp($na, $nb) <=> 0;
            }
        }

        return 0;
    }

    private static function order(string $c): int
    {
        if ('~' === $c) {
            return -1;
        }
        if (ctype_alpha($c)) {
            return \ord($c);
        }

        return \ord($c) + 256;
    }
}
