<?php

namespace App\Audit;

/**
 * Gravité d'une vulnérabilité OSV, ramenée à quatre niveaux (+ « inconnue ») :
 *   critical  → alerte « critique »
 *   high, moderate, unknown → « avertissement »
 *   low → information
 * Source : niveau GitHub (database_specific.severity) s'il existe, sinon score CVSS v3.
 */
final class Severity
{
    public const array ORDER = ['critical' => 5, 'high' => 4, 'moderate' => 3, 'unknown' => 2, 'low' => 1];

    /** @param array<string, mixed> $advisory fiche OSV */
    public static function of(array $advisory): string
    {
        $github = strtolower((string) ($advisory['database_specific']['severity'] ?? ''));
        $mapped = match ($github) {
            'critical' => 'critical',
            'high' => 'high',
            'moderate', 'medium' => 'moderate',
            'low' => 'low',
            default => null,
        };
        if ($mapped) {
            return $mapped;
        }
        foreach ($advisory['severity'] ?? [] as $severity) {
            if (\in_array($severity['type'] ?? '', ['CVSS_V3', 'CVSS_V3_1'], true) && null !== $score = self::cvss3((string) $severity['score'])) {
                return self::fromScore($score);
            }
        }

        return 'unknown';
    }

    public static function max(?string $a, ?string $b): ?string
    {
        if (null === $a) {
            return $b;
        }
        if (null === $b) {
            return $a;
        }

        return self::ORDER[$a] >= self::ORDER[$b] ? $a : $b;
    }

    public static function fromScore(float $score): string
    {
        return match (true) {
            $score >= 9.0 => 'critical',
            $score >= 7.0 => 'high',
            $score >= 4.0 => 'moderate',
            $score > 0.0 => 'low',
            default => 'unknown',
        };
    }

    /** Score de base CVSS v3.x à partir du vecteur (spécification FIRST 3.1), null si illisible. */
    public static function cvss3(string $vector): ?float
    {
        if (!preg_match('#^CVSS:3\.[01]/#', $vector)) {
            return null;
        }
        $m = [];
        foreach (explode('/', substr($vector, 9)) as $part) {
            [$key, $value] = array_pad(explode(':', $part, 2), 2, '');
            $m[$key] = $value;
        }
        $changed = 'C' === ($m['S'] ?? '');
        $av = ['N' => 0.85, 'A' => 0.62, 'L' => 0.55, 'P' => 0.2][$m['AV'] ?? ''] ?? null;
        $ac = ['L' => 0.77, 'H' => 0.44][$m['AC'] ?? ''] ?? null;
        $pr = ($changed ? ['N' => 0.85, 'L' => 0.68, 'H' => 0.5] : ['N' => 0.85, 'L' => 0.62, 'H' => 0.27])[$m['PR'] ?? ''] ?? null;
        $ui = ['N' => 0.85, 'R' => 0.62][$m['UI'] ?? ''] ?? null;
        $cia = ['H' => 0.56, 'L' => 0.22, 'N' => 0.0];
        $c = $cia[$m['C'] ?? ''] ?? null;
        $i = $cia[$m['I'] ?? ''] ?? null;
        $a = $cia[$m['A'] ?? ''] ?? null;
        if (null === $av || null === $ac || null === $pr || null === $ui || null === $c || null === $i || null === $a) {
            return null;
        }

        $iss = 1 - (1 - $c) * (1 - $i) * (1 - $a);
        $impact = $changed ? 7.52 * ($iss - 0.029) - 3.25 * (($iss - 0.02) ** 15) : 6.42 * $iss;
        if ($impact <= 0) {
            return 0.0;
        }
        $exploitability = 8.22 * $av * $ac * $pr * $ui;
        $base = $changed ? min(1.08 * ($impact + $exploitability), 10) : min($impact + $exploitability, 10);

        return self::roundUp($base);
    }

    /** Arrondi supérieur à une décimale, tel que défini par CVSS 3.1 */
    private static function roundUp(float $value): float
    {
        $int = (int) round($value * 100000);

        return 0 === $int % 10000 ? $int / 100000.0 : (floor($int / 10000) + 1) / 10.0;
    }
}
