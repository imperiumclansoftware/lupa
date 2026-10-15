<?php

namespace App\Search;

use App\Resolver\ComposerResolver;
use App\Resolver\NpmResolver;
use Sas\Bundle\Ecosystem;

/**
 * Remplace les lignes à motif d'une liste par les paquets correspondants, en gardant la contrainte de version :
 *   @syncfusion/ej2-*angular*@^29   →  @syncfusion/ej2-angular-grids@^29, @syncfusion/ej2-angular-base@^29…
 *   symfony/*-bundle:^7.3           →  symfony/framework-bundle:^7.3, symfony/twig-bundle:^7.3…
 *   django-rest-*>=3                →  django-rest-framework>=3, …
 *   node:24-*alpine                 →  node:24-alpine, node:24-alpine3.23…   (étiquettes)
 *   bitnami/*-exporter:latest       →  bitnami/node-exporter:latest, …       (dépôts Docker Hub)
 */
final class PatternExpander
{
    public function __construct(
        private PackageSearch $search,
        private VersionFilter $versions,
    ) {
    }

    /**
     * @param list<string> $lines
     *
     * @return array{lines: list<string>, patterns: array<string, array{lines: list<string>, skipped: list<string>}>}
     */
    public function expand(Ecosystem $ecosystem, array $lines): array
    {
        $result = [];
        $patterns = [];
        foreach ($lines as $line) {
            $expanded = $this->expandLine($ecosystem, $line);
            if (null === $expanded) {
                $result[] = $line;
                continue;
            }
            $patterns[$line] = $expanded;
            array_push($result, ...$expanded['lines']);
        }

        return ['lines' => array_values(array_unique($result)), 'patterns' => $patterns];
    }

    /**
     * Lignes obtenues, et paquets écartés faute de version respectant la contrainte ; null si la ligne n'est pas un motif.
     *
     * @return array{lines: list<string>, skipped: list<string>}|null
     */
    public function expandLine(Ecosystem $ecosystem, string $line): ?array
    {
        $line = trim($line);
        if (!Glob::isPattern($line)) {
            return null;
        }
        $this->skipped = [];

        $lines = match ($ecosystem) {
            Ecosystem::Npm => $this->npm($line),
            Ecosystem::Pypi => $this->pypi($line),
            Ecosystem::Composer => $this->composer($line),
            Ecosystem::Docker => $this->docker($line),
        };
        if ([] === $lines) {
            throw new \InvalidArgumentException([] === $this->skipped
                ? sprintf('Motif « %s » : aucun paquet ne correspond.', $line)
                : sprintf('Motif « %s » : %d paquet(s) trouvé(s), mais aucun n\'a de version respectant la contrainte.', $line, \count($this->skipped)));
        }

        return ['lines' => $lines, 'skipped' => $this->skipped];
    }

    /** Paquets du dernier motif écartés faute de version compatible */
    private array $skipped = [];

    /**
     * Noms correspondant au motif, limités, puis filtrés par la contrainte de version.
     *
     * @return list<string>
     */
    private function names(Ecosystem $ecosystem, string $pattern, ?string $constraint): array
    {
        $names = $this->search->names($ecosystem, $pattern);
        if (\count($names) > PackageSearch::MAX_MATCHES) {
            throw new \InvalidArgumentException(sprintf('Motif « %s » trop large : %d correspondances (%d au plus). Précisez-le.', $pattern, \count($names), PackageSearch::MAX_MATCHES));
        }
        if (null === $constraint || [] === $names) {
            return $names;
        }
        $filtered = $this->versions->filter($ecosystem, $this->search->versionsMany($ecosystem, $names), $constraint);
        $this->skipped = $filtered['skipped'];

        return $filtered['keep'];
    }

    /** @return list<string> */
    private function npm(string $line): array
    {
        [$pattern, $range] = NpmResolver::parseSpec($line);
        if (Glob::isPattern($range)) {
            throw new \InvalidArgumentException(sprintf('« %s » : le motif porte sur le nom ; pour la version, utilisez une plage semver (ex. ^29, 29.x).', $line));
        }

        // Étiquette (latest, next…) : pas de contrainte semver à vérifier
        $constraint = preg_match('/^[a-z][a-z0-9.-]*$/i', $range) ? null : $range;

        return array_map(static fn (string $name) => 'latest' === $range ? $name : $name.'@'.$range, $this->names(Ecosystem::Npm, $pattern, $constraint));
    }

    /** @return list<string> */
    private function pypi(string $line): array
    {
        if (!preg_match('/^([A-Za-z0-9._*?-]+)(.*)$/', $line, $m) || !Glob::isPattern($m[1])) {
            throw new \InvalidArgumentException(sprintf('« %s » : le motif porte sur le nom (ex. django-rest-*>=3).', $line));
        }

        // Contrainte sans extras ni marqueurs : « [standard]>=3; python_version>'3.9' » → « >=3 »
        $specifier = trim((string) preg_replace(['/^\[[^\]]*\]/', '/;.*$/'], '', trim($m[2])));

        return array_map(static fn (string $name) => $name.$m[2], $this->names(Ecosystem::Pypi, $m[1], '' === $specifier ? null : $specifier));
    }

    /** @return list<string> */
    private function composer(string $line): array
    {
        [$pattern, $constraint] = ComposerResolver::parseSpec($line);
        if (Glob::isPattern($constraint) && '*' !== $constraint) {
            throw new \InvalidArgumentException(sprintf('« %s » : le motif porte sur le nom ; pour la version, utilisez une contrainte (ex. ^7.3).', $line));
        }

        return array_map(static fn (string $name) => '*' === $constraint ? $name : $name.':'.$constraint, $this->names(Ecosystem::Composer, $pattern, '*' === $constraint ? null : $constraint));
    }

    /** @return list<string> */
    private function docker(string $line): array
    {
        if (str_contains($line, '@')) {
            throw new \InvalidArgumentException(sprintf('« %s » : un motif ne peut pas être combiné à une empreinte @sha256.', $line));
        }
        // Étiquette = après le dernier « : » qui suit le dernier « / »
        $slash = strrpos($line, '/');
        $colon = strrpos($line, ':');
        [$repository, $tag] = false !== $colon && (false === $slash || $colon > $slash)
            ? [substr($line, 0, $colon), substr($line, $colon + 1)] : [$line, 'latest'];

        $repositories = Glob::isPattern($repository) ? $this->names(Ecosystem::Docker, $repository, null) : [$repository];
        $lines = [];
        foreach ($repositories as $repo) {
            foreach (Glob::isPattern($tag) ? $this->search->tags($repo, $tag) : [$tag] as $t) {
                $lines[] = $repo.':'.$t;
            }
            if (\count($lines) > PackageSearch::MAX_MATCHES) {
                throw new \InvalidArgumentException(sprintf('Motif « %s » trop large : plus de %d images. Précisez-le.', $line, PackageSearch::MAX_MATCHES));
            }
        }

        return $lines;
    }
}
