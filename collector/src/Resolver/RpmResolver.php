<?php

namespace App\Resolver;

use App\Blacklist\Blacklist;
use App\Entity\Artifact;
use App\Entity\PackageList;
use App\Http\Downloader;
use App\Job\JobContext;
use App\Search\Glob;
use App\System\RpmIndex;
use App\System\RpmRepository;
use Sas\Bundle\Distributions;
use Sas\Bundle\Ecosystem;
use Sas\Bundle\RpmVersion;

/**
 * RPM (AlmaLinux, CentOS) : les Requires (et Recommends en option) sont suivis comme le ferait dnf pour
 * une installation sur un système vide : capacités (provides), fichiers (/usr/bin/python3), dépendances
 * booléennes (« (a or b) », « (a if b) »). EL8 : seuls les streams de modules par défaut sont retenus,
 * sauf « @module:stream » dans la liste.
 */
final readonly class RpmResolver implements Resolver
{
    private const string SPEC = '/^(?:@([a-z0-9_.+-]+):([a-z0-9_.+-]+)|([a-zA-Z0-9_*?][a-zA-Z0-9_.+*?\-]*)(?:\s*=\s*(\S+))?)$/';

    public function __construct(
        private RpmIndex $index,
        private Downloader $downloader,
        private ArtifactCatalog $catalog,
        private Blacklist $blacklist,
    ) {
    }

    public function ecosystem(): Ecosystem
    {
        return Ecosystem::Rpm;
    }

    public function validate(PackageList $list): array
    {
        $errors = SystemOptions::validate($list);
        foreach ($list->getSpecLines() as $line) {
            if (!preg_match(self::SPEC, $line)) {
                $errors[] = sprintf('« %s » : attendu un nom de paquet, éventuellement avec =version (ex. nginx, nginx=1.20.1, @nodejs:20 pour un module EL8).', $line);
            }
        }

        return $errors;
    }

    public function resolve(PackageList $list, JobContext $context): array
    {
        $distribution = SystemOptions::distribution($list);
        $dist = Distributions::find($distribution);
        $artifacts = [];
        foreach (SystemOptions::architectures($list) as $arch) {
            $context->log(sprintf('%s, %s : lecture des index.', $dist['label'], $arch));
            $repository = $this->index->load($distribution, $arch, SystemOptions::sections($list), $context);
            $context->log(sprintf('%d paquets disponibles.', $repository->count()));
            $context->report(sprintf('Résolution des dépendances (%s)', $arch));
            $selected = $this->select($repository, $list->getSpecLines(), SystemOptions::recommends($list), $context);
            $context->log(sprintf('%s : %d paquets (dépendances comprises).', $arch, \count($selected)));
            array_push($artifacts, ...$this->download($repository, $selected, $distribution, $arch, $context));
        }

        return $artifacts;
    }

    /**
     * @param list<string> $specs
     *
     * @return array<string, int> nom.arch => paquet retenu
     */
    public function select(RpmRepository $repository, array $specs, bool $recommends, JobContext $context): array
    {
        // EL8 : streams de modules autorisés (par défaut, ou demandés par « @module:stream »)
        $streams = $repository->defaultStreams;
        $names = [];
        foreach ($specs as $line) {
            preg_match(self::SPEC, $line, $m);
            if ('' !== ($m[1] ?? '')) {
                $streams[$m[1]] = $m[2];
                continue;
            }
            $names[] = [$m[3], $m[4] ?? null];
        }
        $allowed = function (int $id) use ($repository, $streams): bool {
            $nevra = $this->nevraOf($repository, $id);
            $module = $repository->modular[$nevra] ?? null;
            if (null === $module) {
                return true;
            }
            [$name, $stream] = explode(':', $module, 2);
            if (($streams[$name] ?? null) !== $stream) {
                return false;
            }
            // Contexte du module : compilé pour les streams activés de ses dépendances (perl:5.26…)
            $entries = $repository->moduleRequires[$nevra] ?? [];
            foreach ($entries as $requires) {
                $ok = true;
                foreach ($requires as $required => $wanted) {
                    if ([] !== $wanted && !\in_array($streams[$required] ?? '', $wanted, true)) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    return true;
                }
            }

            return [] === $entries;
        };

        $state = new RpmState();
        foreach ($names as [$name, $version]) {
            $matches = Glob::isPattern($name) ? array_values(array_filter($repository->names(), static fn (string $n) => Glob::matches($name, $n))) : [$name];
            if (Glob::isPattern($name)) {
                $context->log(sprintf('Motif « %s » : %d paquet(s).', $name, \count($matches)));
            }
            foreach ($matches as $n) {
                $found = null;
                foreach ($repository->versions($n) as $id) {
                    $p = $repository->package($id);
                    if ($allowed($id) && (null === $version || $p['v'] === $version || RpmRepository::evr($p) === $version || $p['v'].'-'.$p['r'] === $version)) {
                        $found = $id;
                        break;
                    }
                }
                // Nom d'une capacité plutôt que d'un paquet (ex. « python3 » fourni par python3.11)
                $found ??= null === $version ? $this->provider($repository, $n, null, null, $allowed) : null;
                if (null === $found) {
                    throw new \RuntimeException(sprintf('Paquet introuvable : %s%s (vérifiez le nom, la distribution et les dépôts choisis).', $n, $version ? ' = '.$version : ''));
                }
                $state->queue[] = $found;
            }
        }

        while ([] !== $state->queue) {
            $id = array_shift($state->queue);
            $p = $repository->package($id);
            $key = $p['n'].'.'.$p['a'];
            if (isset($state->selected[$key])) {
                continue;
            }
            $state->selected[$key] = $id;
            $deps = RpmRepository::parseDeps($repository->deps($id, 'requires'));
            if ($recommends) {
                $deps = [...$deps, ...RpmRepository::parseDeps($repository->deps($id, 'recommends'))];
            }
            foreach ($deps as [$name, $flags, $evr]) {
                if (str_starts_with($name, '(')) {
                    $this->rich($repository, $name, $state, $allowed, $p['n']);
                } else {
                    $this->require($repository, $name, $flags, $evr, $state, $allowed, $p['n']);
                }
            }
        }
        foreach ($state->missing as $dependency => $by) {
            $context->log(sprintf('ATTENTION dépendance introuvable : %s (requise par %s).', $dependency, $by));
        }

        return $state->selected;
    }

    private function nevraOf(RpmRepository $repository, int $id): string
    {
        $p = $repository->package($id);

        return sprintf('%s-%d:%s-%s.%s', $p['n'], $p['e'], $p['v'], $p['r'], $p['a']);
    }

    /** Capacité simple : déjà fournie par un paquet retenu, sinon le meilleur fournisseur est ajouté. */
    private function require(RpmRepository $repository, string $name, ?string $flags, ?string $evr, RpmState $state, \Closure $allowed, string $by): bool
    {
        if ($this->provided($repository, $name, $flags, $evr, $state)) {
            return true;
        }
        $id = $this->provider($repository, $name, $flags, $evr, $allowed);
        if (null === $id) {
            $state->missing[$name.($flags ? " $flags $evr" : '')] = $by;

            return false;
        }
        $state->queue[] = $id;
        // Retenu tout de suite : les dépendances suivantes le voient (évite deux fournisseurs concurrents)
        $p = $repository->package($id);
        $state->pending[$p['n'].'.'.$p['a']] = $id;

        return true;
    }

    private function provided(RpmRepository $repository, string $name, ?string $flags, ?string $evr, RpmState $state): bool
    {
        foreach ($repository->providers($name) as $id) {
            $p = $repository->package($id);
            $key = $p['n'].'.'.$p['a'];
            if ((($state->selected[$key] ?? null) === $id || ($state->pending[$key] ?? null) === $id)
                && $repository->satisfies($id, $name, $flags, $evr)) {
                return true;
            }
        }

        return false;
    }

    private function provider(RpmRepository $repository, string $name, ?string $flags, ?string $evr, \Closure $allowed): ?int
    {
        $candidates = array_values(array_filter($repository->providers($name), static fn (int $id) => $allowed($id) && $repository->satisfies($id, $name, $flags, $evr)));
        if ([] === $candidates) {
            return null;
        }
        // Le paquet de même nom d'abord, puis le nom le plus court (python3 plutôt que python3.12-…), le plus récent
        usort($candidates, static function (int $a, int $b) use ($repository, $name): int {
            $pa = $repository->package($a);
            $pb = $repository->package($b);

            return (($pb['n'] === $name) <=> ($pa['n'] === $name))
                ?: (\strlen($pa['n']) <=> \strlen($pb['n']))
                ?: RpmVersion::compare(RpmRepository::evr($pb), RpmRepository::evr($pa));
        });

        return $candidates[0];
    }

    /** Dépendance booléenne (RPM ≥ 4.13) : and, or, if, unless, with, without. */
    private function rich(RpmRepository $repository, string $expression, RpmState $state, \Closure $allowed, string $by): bool
    {
        $tokens = preg_split('/(\(|\))|\s+/', $expression, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY) ?: [];
        $position = 0;
        $tree = self::parseRich($tokens, $position);

        return $this->evaluate($repository, $tree, $state, $allowed, $by, true);
    }

    /** @return array<mixed> ['op', gauche, droite…] ou ['dep', nom, flags, evr] */
    private static function parseRich(array $tokens, int &$i): array
    {
        if ('(' === ($tokens[$i] ?? null)) {
            ++$i;
            $operands = [self::parseRich($tokens, $i)];
            $operators = [];
            while (isset($tokens[$i]) && ')' !== $tokens[$i]) {
                $operators[] = $tokens[$i++];
                $operands[] = self::parseRich($tokens, $i);
            }
            ++$i;
            if ([] === $operators) {
                return $operands[0];
            }
            // « a if b else c », « a unless b else c »
            if (\in_array($operators[0], ['if', 'unless'], true)) {
                return [$operators[0], $operands[0], $operands[1], $operands[2] ?? null];
            }

            return [$operators[0], ...$operands];
        }
        $name = $tokens[$i++] ?? '';
        $ops = ['=' => 'EQ', '<' => 'LT', '<=' => 'LE', '>' => 'GT', '>=' => 'GE'];
        if (isset($tokens[$i], $ops[$tokens[$i]])) {
            $flags = $ops[$tokens[$i++]];

            return ['dep', $name, $flags, $tokens[$i++] ?? ''];
        }

        return ['dep', $name, null, null];
    }

    private function evaluate(RpmRepository $repository, ?array $node, RpmState $state, \Closure $allowed, string $by, bool $install): bool
    {
        if (null === $node) {
            return true;
        }
        return match ($node[0]) {
            'dep' => $install ? $this->require($repository, $node[1], $node[2], $node[3], $state, $allowed, $by) : $this->provided($repository, $node[1], $node[2], $node[3], $state),
            'and', 'with' => array_reduce(\array_slice($node, 1), fn (bool $ok, array $n) => $this->evaluate($repository, $n, $state, $allowed, $by, $install) && $ok, true),
            'without' => $this->evaluate($repository, $node[1], $state, $allowed, $by, $install),
            'or' => $this->any($repository, \array_slice($node, 1), $state, $allowed, $by, $install),
            // Conditionnelles : la condition n'est jamais installée pour elle-même
            'if' => $this->evaluate($repository, $node[2], $state, $allowed, $by, false)
                ? $this->evaluate($repository, $node[1], $state, $allowed, $by, $install)
                : $this->evaluate($repository, $node[3], $state, $allowed, $by, $install),
            'unless' => !$this->evaluate($repository, $node[2], $state, $allowed, $by, false)
                ? $this->evaluate($repository, $node[1], $state, $allowed, $by, $install)
                : $this->evaluate($repository, $node[3], $state, $allowed, $by, $install),
            default => true,
        };
    }

    /** « or » : déjà satisfait par l'un, sinon le premier installable. */
    private function any(RpmRepository $repository, array $nodes, RpmState $state, \Closure $allowed, string $by, bool $install): bool
    {
        foreach ($nodes as $node) {
            if ($this->evaluate($repository, $node, $state, $allowed, $by, false)) {
                return true;
            }
        }
        if (!$install) {
            return false;
        }
        foreach ($nodes as $node) {
            $missing = $state->missing;
            if ($this->evaluate($repository, $node, $state, $allowed, $by, true)) {
                return true;
            }
            $state->missing = $missing;
        }

        return false;
    }

    /**
     * @param array<string, int> $selected
     *
     * @return list<Artifact>
     */
    private function download(RpmRepository $repository, array $selected, string $distribution, string $arch, JobContext $context): array
    {
        ksort($selected);

        $artifacts = [];
        $done = 0;
        foreach ($selected as $id) {
            ++$done;
            $p = $repository->package($id);
            $version = RpmRepository::evr($p);
            if ($this->blacklist->blocks(Ecosystem::Rpm, $p['n'], $version, $context)) {
                continue;
            }
            // Rangé par architecture de dépôt : x86_64 et x86_64_v2 (AlmaLinux 10) publient des fichiers de même nom
            $filename = $distribution.'/'.$arch.'/'.basename($p['loc']);
            $artifact = $this->catalog->complete(Ecosystem::Rpm, $p['n'], $version, $filename);
            if (!$artifact) {
                $context->report(sprintf('Téléchargement %s-%s.%s', $p['n'], $version, $p['a']), (int) ($done * 100 / max(1, \count($selected))));
                $source = $repository->sources[$p['src']];
                $url = $source['base'].'/'.$p['loc'];
                $blob = 'sha256' === $p['ht'] ? $this->downloader->fetch($url, sha256: $p['h']) : $this->downloader->fetch($url, checks: [$p['ht'] => $p['h']]);
                $artifact = $this->catalog->record(Ecosystem::Rpm, $p['n'], $version, $filename, [$blob], [
                    'distribution' => $distribution,
                    'architecture' => $p['a'],
                    'repoArch' => $arch,
                    'repository' => $source['name'],
                    'description' => $p['d'],
                    'primary' => RpmIndex::xml($repository->pdo($p['src']), $p['key']),
                ]);
            }
            $artifacts[] = $artifact;
            if (0 === $done % 50) {
                $this->catalog->flush();
            }
        }
        $this->catalog->flush();

        return $artifacts;
    }
}
