<?php

namespace App\Resolver;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

/**
 * Contraintes de version de Terraform (« ~> 5.0, != 5.1.0 ») et d'Ansible Galaxy (« >=2.0.0,<3 »),
 * évaluées avec composer/semver (même sémantique : « ~> 5.0 » = « ~5.0 » = >=5.0 <6.0).
 */
final class Constraint
{
    public static function normalize(string $constraint): string
    {
        $constraint = trim($constraint);
        if ('' === $constraint || '*' === $constraint || 'latest' === $constraint) {
            return '*';
        }
        $parts = [];
        foreach (array_filter(array_map('trim', explode(',', $constraint))) as $part) {
            $part = (string) preg_replace('/^~>\s*/', '~', $part);
            $part = (string) preg_replace('/^==?\s*/', '', $part);
            $parts[] = (string) preg_replace('/^(>=|<=|!=|>|<)\s+/', '$1', $part);
        }

        return implode(',', $parts) ?: '*';
    }

    public static function isValid(string $constraint): bool
    {
        try {
            (new VersionParser())->parseConstraints(self::normalize($constraint));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Version exacte demandée (sans opérateur), ou null. */
    public static function exact(string $constraint): ?string
    {
        $normalized = self::normalize($constraint);

        return preg_match('/^v?\d+(\.\d+)*([-+][\w.]+)?$/', $normalized) ? $normalized : null;
    }

    /**
     * Plus haute version qui satisfait la contrainte ; les préversions seulement si demandées exactement.
     *
     * @param list<string> $versions
     */
    public static function best(array $versions, string $constraint): ?string
    {
        $normalized = self::normalize($constraint);
        $exact = self::exact($constraint);
        $candidates = [];
        foreach ($versions as $version) {
            if (null !== $exact) {
                if ($version === $exact || ltrim($version, 'v') === ltrim($exact, 'v')) {
                    return $version;
                }
                continue;
            }
            if (preg_match('/-/', $version)) {
                continue;
            }
            try {
                if (Semver::satisfies($version, $normalized)) {
                    $candidates[] = $version;
                }
            } catch (\Throwable) {
                // version au format inattendu : ignorée
            }
        }
        if ([] === $candidates) {
            return null;
        }
        usort($candidates, static fn (string $a, string $b) => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));

        return $candidates[0];
    }
}
