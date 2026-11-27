<?php

namespace App\Resolver;

use App\Entity\PackageList;
use App\Job\JobContext;

/**
 * npm (package.json), Composer (composer.json) et pip (requirements.txt) ne retiennent qu'une version par
 * paquet dans une même résolution. Quand une liste demande plusieurs versions d'un même paquet (plage
 * « [toutes] », ou plusieurs lignes), on résout en plusieurs passes : la passe n contient la n-ième
 * version de chaque paquet demandé plusieurs fois ; les autres lignes sont résolues dans la première.
 *
 *   react@16.0.0, react@17.0.0, lodash@^4  →  [react@16.0.0, lodash@^4], [react@17.0.0]
 */
final class SpecRounds
{
    /**
     * @template T
     *
     * @param list<T>             $specs
     * @param callable(T): string $key   identité du paquet (nom normalisé)
     *
     * @return list<list<T>> au moins une passe (vide si aucune ligne)
     */
    public static function split(array $specs, callable $key): array
    {
        $groups = [];
        foreach ($specs as $spec) {
            $groups[$key($spec)][] = $spec;
        }

        $rounds = [[]];
        foreach ($groups as $group) {
            foreach ($group as $i => $spec) {
                $rounds[$i][] = $spec;
            }
        }

        return array_values($rounds);
    }

    /**
     * Résout les lignes passe par passe. Si une passe échoue et contient des versions issues d'une plage
     * « [toutes] », les lignes ordinaires sont rejouées seules (leur échec fait toujours échouer la liste),
     * puis chaque version de plage isolément : celles qui échouent encore sont ignorées et notées dans la
     * liste (PackageList::getSkippedVersions()), avec la raison.
     *
     * @template R
     *
     * @param list<string>                      $lines
     * @param callable(string): string          $key   identité du paquet
     * @param callable(list<string>, string): R $run   résolution d'un groupe de lignes ; reçoit un libellé de passe
     * @param string                            $label suffixe des messages (ex. « Python 3.12, Linux x86_64 »)
     *
     * @return list<R> résultats des résolutions réussies
     */
    public static function resolve(array $lines, callable $key, callable $run, PackageList $list, JobContext $context, string $label = ''): array
    {
        $rounds = self::split($lines, $key);
        $count = \count($rounds);
        $results = [];
        foreach ($rounds as $i => $round) {
            $pass = $count > 1 ? sprintf('passe %d/%d', $i + 1, $count) : '';
            try {
                $results[] = $run($round, $pass);
                continue;
            } catch (\RuntimeException $e) {
                $optional = array_values(array_filter($round, $list->isRangeVersion(...)));
                if ([] === $optional) {
                    throw $e;
                }
            }

            // Échec avec des versions de plage : on isole celles qui posent problème
            $required = array_values(array_diff($round, $optional));
            if ([] !== $required) {
                $results[] = $run($required, $pass);
            }
            foreach ($optional as $line) {
                try {
                    $results[] = $run([$line], $pass);
                } catch (\RuntimeException $e) {
                    $reason = self::reason($e->getMessage());
                    $list->skipVersion($line.('' !== $label ? ' ('.$label.')' : ''), $reason);
                    $context->log(sprintf('ATTENTION version ignorée, résolution impossible : %s%s : %s', $line, '' !== $label ? ' ('.$label.')' : '', $reason));
                }
            }
        }
        if ($count > 1) {
            $context->log(sprintf('Plusieurs versions d\'un même paquet%s : %d résolutions séparées.', '' !== $label ? ' ('.$label.')' : '', $count));
        }

        return $results;
    }

    /** Raison lisible sur une ligne : la cause explicite de l'outil (npm, Composer, pip), sinon la première ligne d'erreur */
    private static function reason(string $message): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $message) ?: []), static fn (string $l) => '' !== $l && !str_starts_with($l, 'La commande')));
        foreach (['/No matching|notarget|Could not find|could not be found|not satisfiable|requires|conflict|ERESOLVE|ResolutionImpossible/i', '/\b(ERR!|error|Problem)\b/i'] as $pattern) {
            foreach ($lines as $line) {
                if (preg_match($pattern, $line)) {
                    // pip : « … (from versions: 1.26.0, 1.26.1, …) » n'apporte rien à la raison
                    return mb_strimwidth((string) preg_replace('/\s*\(from versions:.*$/', '', $line), 0, 300, '…');
                }
            }
        }

        return mb_strimwidth($lines[0] ?? 'erreur inconnue', 0, 300, '…');
    }
}
