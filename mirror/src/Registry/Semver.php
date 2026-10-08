<?php

namespace App\Registry;

/** Comparaison de versions semver (npm), suffisante pour trier et choisir « latest ». */
final class Semver
{
    public static function compare(string $a, string $b): int
    {
        $pa = self::parse($a);
        $pb = self::parse($b);
        if (null === $pa || null === $pb) {
            return strnatcmp($a, $b);
        }
        for ($i = 0; $i < 3; ++$i) {
            if ($pa[$i] !== $pb[$i]) {
                return $pa[$i] <=> $pb[$i];
            }
        }
        // Une préversion est inférieure à la version finale
        if ('' === $pa[3] || '' === $pb[3]) {
            return ('' === $pa[3]) <=> ('' === $pb[3]);
        }
        $ia = explode('.', $pa[3]);
        $ib = explode('.', $pb[3]);
        foreach ($ia as $k => $x) {
            if (!isset($ib[$k])) {
                return 1;
            }
            $y = $ib[$k];
            $cmp = ctype_digit($x) && ctype_digit($y) ? (int) $x <=> (int) $y : (ctype_digit($x) ? -1 : (ctype_digit($y) ? 1 : strcmp($x, $y)));
            if (0 !== $cmp) {
                return $cmp;
            }
        }

        return \count($ia) <=> \count($ib);
    }

    public static function isPrerelease(string $version): bool
    {
        return '' !== (self::parse($version)[3] ?? '');
    }

    /** @return array{int, int, int, string}|null */
    private static function parse(string $version): ?array
    {
        if (!preg_match('/^v?(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/', $version, $m)) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3], $m[4] ?? ''];
    }
}
