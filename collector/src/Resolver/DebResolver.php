<?php

namespace App\Resolver;

use App\Blacklist\Blacklist;
use App\Entity\Artifact;
use App\Entity\PackageList;
use App\Http\Downloader;
use App\Job\JobContext;
use App\Search\Glob;
use App\System\DebIndex;
use App\System\DebRepository;
use Sas\Bundle\DebVersion;
use Sas\Bundle\Distributions;
use Sas\Bundle\Ecosystem;

/**
 * Debian : les dépendances (Depends, Pre-Depends, et Recommends en option) sont suivies comme le ferait apt
 * pour une installation sur un système vide : alternatives (« a | b »), paquets virtuels, contraintes de
 * version. Le miroir peut ensuite installer ces paquets avec apt sans accès à Internet.
 */
final readonly class DebResolver implements Resolver
{
    private const string SPEC = '/^([a-z0-9*?][a-z0-9+.*?\-]*)(?:\s*=\s*(\S+))?$/i';

    public function __construct(
        private DebIndex $index,
        private Downloader $downloader,
        private ArtifactCatalog $catalog,
        private Blacklist $blacklist,
    ) {
    }

    public function ecosystem(): Ecosystem
    {
        return Ecosystem::Deb;
    }

    public function validate(PackageList $list): array
    {
        $errors = SystemOptions::validate($list);
        foreach ($list->getSpecLines() as $line) {
            if (!preg_match(self::SPEC, $line)) {
                $errors[] = sprintf('« %s » : attendu un nom de paquet, éventuellement avec =version (ex. nginx, curl=7.88.1-10+deb12u8).', $line);
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
            $context->log(sprintf('%d paquets disponibles.', \count($repository->packages)));
            $context->report(sprintf('Résolution des dépendances (%s)', $arch));
            $selected = $this->select($repository, $list->getSpecLines(), $arch, SystemOptions::recommends($list), $context);
            $context->log(sprintf('%s : %d paquets (dépendances comprises).', $arch, \count($selected)));
            array_push($artifacts, ...$this->download($repository, $selected, $distribution, $arch, $context));
        }

        return $artifacts;
    }

    /**
     * @param list<string> $specs
     *
     * @return array<string, int> nom => paquet retenu
     */
    public function select(DebRepository $repository, array $specs, string $arch, bool $recommends, JobContext $context): array
    {
        $selected = [];
        $queue = [];
        foreach ($specs as $line) {
            preg_match(self::SPEC, $line, $m);
            [$name, $version] = [strtolower($m[1]), $m[2] ?? null];
            $names = Glob::isPattern($name) ? array_values(array_filter($repository->names(), static fn (string $n) => Glob::matches($name, $n))) : [$name];
            if (Glob::isPattern($name)) {
                $context->log(sprintf('Motif « %s » : %d paquet(s).', $name, \count($names)));
            }
            foreach ($names as $n) {
                $id = $this->candidate($repository, $n, $version ? '=' : null, $version, $arch);
                if (null === $id) {
                    throw new \RuntimeException(sprintf('Paquet introuvable : %s%s (vérifiez le nom, la distribution et les sections).', $n, $version ? ' = '.$version : ''));
                }
                $queue[] = $id;
            }
        }

        $missing = [];
        while (null !== $id = array_shift($queue)) {
            $p = $repository->packages[$id];
            if (isset($selected[$p['n']])) {
                continue;
            }
            $selected[$p['n']] = $id;
            $fields = $p['dep'].($recommends && '' !== $p['rec'] ? ','.$p['rec'] : '');
            foreach (DebRepository::parseDepends($fields) as $alternatives) {
                if ($this->satisfied($repository, $selected, $alternatives)) {
                    continue;
                }
                $choice = null;
                foreach ($alternatives as [$name, $op, $version]) {
                    if (null !== $choice = $this->candidate($repository, $name, $op, $version, $arch, true)) {
                        break;
                    }
                }
                if (null === $choice) {
                    $missing[implode(' | ', array_map(static fn ($a) => $a[0].($a[1] ? " ($a[1] $a[2])" : ''), $alternatives))] = $p['n'];
                    continue;
                }
                if (!isset($selected[$repository->packages[$choice]['n']])) {
                    $queue[] = $choice;
                }
            }
        }
        foreach ($missing as $dependency => $by) {
            $context->log(sprintf('ATTENTION dépendance introuvable : %s (requise par %s).', $dependency, $by));
        }

        return $selected;
    }

    /** Une alternative est-elle déjà satisfaite par un paquet retenu ? */
    private function satisfied(DebRepository $repository, array $selected, array $alternatives): bool
    {
        foreach ($alternatives as [$name, $op, $version]) {
            if (isset($selected[$name]) && (null === $op || DebVersion::satisfies($repository->packages[$selected[$name]]['v'], $op, (string) $version))) {
                return true;
            }
            foreach ($repository->providers($name) as [$id, $provided]) {
                if (isset($selected[$repository->packages[$id]['n']]) && $selected[$repository->packages[$id]['n']] === $id
                    && (null === $op || (null !== $provided && DebVersion::satisfies($provided, $op, (string) $version)))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Version la plus récente qui convient : paquet réel, sinon fournisseur d'un paquet virtuel. */
    private function candidate(DebRepository $repository, string $name, ?string $op, ?string $version, string $arch, bool $virtual = false): ?int
    {
        foreach ($repository->versions($name) as $id) {
            $p = $repository->packages[$id];
            if (($p['a'] === $arch || 'all' === $p['a']) && (null === $op || DebVersion::satisfies($p['v'], $op, (string) $version))) {
                return $id;
            }
        }
        if (!$virtual) {
            return null;
        }
        $providers = array_filter($repository->providers($name), static fn (array $pv) => null === $op || (null !== $pv[1] && DebVersion::satisfies($pv[1], $op, (string) $version)));
        // Plusieurs fournisseurs (mail-transport-agent…) : le plus court nom, comme le choix habituel d'apt
        usort($providers, static fn (array $a, array $b) => \strlen($repository->packages[$a[0]]['n']) <=> \strlen($repository->packages[$b[0]]['n']) ?: $a[0] <=> $b[0]);
        foreach ($providers as [$id]) {
            $p = $repository->packages[$id];
            if ($p['a'] === $arch || 'all' === $p['a']) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $selected
     *
     * @return list<Artifact>
     */
    private function download(DebRepository $repository, array $selected, string $distribution, string $arch, JobContext $context): array
    {
        $artifacts = [];
        $done = 0;
        ksort($selected);
        foreach ($selected as $id) {
            ++$done;
            $p = $repository->packages[$id];
            if ($this->blacklist->blocks(Ecosystem::Deb, $p['n'], $p['v'], $context)) {
                continue;
            }
            $filename = $distribution.'/'.$p['f'];
            $artifact = $this->catalog->complete(Ecosystem::Deb, $p['n'], $p['v'], $filename);
            if (!$artifact) {
                $context->report(sprintf('Téléchargement %s %s (%s)', $p['n'], $p['v'], $arch), (int) ($done * 100 / max(1, \count($selected))));
                $source = $repository->sources[$p['src']];
                $blob = $this->downloader->fetch($source['base'].'/'.$p['f'], sha256: $p['h']);
                $artifact = $this->catalog->record(Ecosystem::Deb, $p['n'], $p['v'], $filename, [$blob], [
                    'distribution' => $distribution,
                    'architecture' => $p['a'],
                    'component' => $p['c'],
                    'suite' => $source['suite'],
                    'description' => $p['d'],
                    'control' => $repository->stanza($id),
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
