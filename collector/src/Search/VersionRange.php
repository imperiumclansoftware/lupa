<?php

namespace App\Search;

use App\Resolver\ComposerResolver;
use App\Resolver\NpmResolver;
use Sas\Bundle\Ecosystem;

/**
 * Plage de versions « [toutes] » : la ligne est remplacée, à chaque mise à jour, par toutes les versions
 * stables publiées qui respectent la contrainte ; chacune est ensuite résolue avec ses dépendances.
 *
 *   react@>=16 <19 [toutes]            →  react@16.0.0, react@16.1.0 … react@18.3.1
 *   symfony/console:>=6.4 <8 [toutes]  →  symfony/console:v6.4.0 … symfony/console:v7.3.4
 *   django>=4,<6 [toutes]              →  django==4.0, django==4.0.1 … django==5.2.7
 *
 * Sans marqueur, une contrainte ne retient qu'une version (la plus récente qui la respecte).
 */
final readonly class VersionRange
{
    public const string MARKER = '[toutes]';

    /** Au-delà, la plage est refusée : chaque version demande sa propre résolution des dépendances */
    public const int MAX_VERSIONS = 300;

    private const string SUFFIX = '/\s*\[toutes\]\s*$/iu';

    public function __construct(
        private PackageSearch $search,
        private VersionFilter $versions,
    ) {
    }

    public static function isRange(string $line): bool
    {
        return 1 === preg_match(self::SUFFIX, $line);
    }

    /**
     * Versions exactes de la plage, une ligne par version, de la plus ancienne à la plus récente.
     *
     * @return array{lines: list<string>, skipped: list<string>}
     */
    public function expandLine(Ecosystem $ecosystem, string $line): array
    {
        [$name, $constraint, $build] = self::parse($ecosystem, $line);
        if (Glob::isPattern($name)) {
            throw new \InvalidArgumentException(sprintf('« %s » : une plage %s porte sur un seul paquet ; les motifs (« * », « ? ») ne s\'y combinent pas.', trim($line), self::MARKER));
        }

        $published = $this->search->versionsMany($ecosystem, [$name])[$name] ?? [];
        if ([] === $published) {
            throw new \InvalidArgumentException(sprintf('« %s » : paquet introuvable dans le registre, ou aucune version publiée.', trim($line)));
        }
        $versions = $this->versions->matching($ecosystem, $published, $constraint);
        if ([] === $versions) {
            throw new \InvalidArgumentException(sprintf('« %s » : aucune version stable ne respecte la contrainte « %s ».', trim($line), $constraint));
        }
        if (\count($versions) > self::MAX_VERSIONS) {
            throw new \InvalidArgumentException(sprintf('« %s » : %d versions (%d au plus, chacune est résolue séparément). Resserrez la plage.', trim($line), \count($versions), self::MAX_VERSIONS));
        }

        return ['lines' => array_map($build, $versions), 'skipped' => []];
    }

    /**
     * Nom, contrainte (vide = toutes les versions stables) et fabrique de la ligne exacte pour une version.
     *
     * @return array{string, string, \Closure(string): string}
     */
    public static function parse(Ecosystem $ecosystem, string $line): array
    {
        $spec = trim((string) preg_replace(self::SUFFIX, '', trim($line)));

        switch ($ecosystem) {
            case Ecosystem::Npm:
                // Sans version : toutes ; une étiquette (next…) est refusée par semver.validRange
                [$name, $range] = NpmResolver::parseSpec($spec);

                return [$name, 'latest' === $range ? '*' : $range, static fn (string $v): string => $name.'@'.$v];

            case Ecosystem::Composer:
                [$name, $constraint] = ComposerResolver::parseSpec($spec);

                return [$name, $constraint, static fn (string $v): string => $name.':'.$v];

            case Ecosystem::Pypi:
                if (!preg_match('/^([A-Za-z0-9][A-Za-z0-9._*?-]*)(\[[^\]]*\])?\s*([^;]*)$/', $spec, $m)) {
                    throw new \InvalidArgumentException(sprintf('« %s » : plage invalide (ex. django>=4,<6 %s ; les marqueurs « ; » ne sont pas pris en charge ici).', trim($line), self::MARKER));
                }
                [, $name, $extras, $specifier] = $m;

                return [$name, trim($specifier), static fn (string $v): string => $name.$extras.'=='.$v];

            default:
                throw new \InvalidArgumentException(sprintf('« %s » : les plages %s sont prises en charge pour npm, Python et Composer seulement.', trim($line), self::MARKER));
        }
    }
}
