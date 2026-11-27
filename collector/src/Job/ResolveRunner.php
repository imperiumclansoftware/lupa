<?php

namespace App\Job;

use App\Audit\Auditor;
use App\Blacklist\Blacklist;
use App\Entity\Artifact;
use App\Entity\Job;
use App\Entity\PackageList;
use App\Resolver\Resolver;
use App\Search\PatternExpander;
use App\Search\VersionRange;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Résout une liste : calcule les dépendances et télécharge ce qui manque. */
final readonly class ResolveRunner implements JobRunner
{
    /** @param iterable<Resolver> $resolvers */
    public function __construct(
        #[AutowireIterator('app.resolver')]
        private iterable $resolvers,
        private EntityManagerInterface $em,
        private PatternExpander $expander,
        private Auditor $auditor,
        private Blacklist $blacklist,
    ) {
    }

    public function type(): string
    {
        return 'resolve';
    }

    public function run(Job $job, JobContext $context): string
    {
        $list = $job->getList() ?? throw new \RuntimeException('La liste a été supprimée.');
        $artifacts = $this->resolveList($list, $context);

        // Analyse de sécurité : une panne d'OSV.dev ne fait pas échouer la mise à jour
        $security = '';
        try {
            $audit = $this->auditor->audit(array_values($artifacts), $context);
            if ($audit['audited'] > 0) {
                $security = ' ; '.AuditRunner::summary($audit);
                $context->log('Sécurité : '.AuditRunner::summary($audit).'.');
            }
        } catch (\Throwable $e) {
            $context->log('ATTENTION analyse de sécurité impossible : '.$e->getMessage());
        }

        $pending = \count(array_filter($artifacts, static fn ($a) => null === $a->getExport()));
        $size = array_sum(array_map(static fn ($a) => $a->getSize(), $artifacts));

        $blocked = $this->blacklist->takeBlocked();
        if ($blocked) {
            $context->log(sprintf('Liste noire : %d version(s) non téléchargée(s) : %s.', \count($blocked), implode(', ', $blocked)));
        }

        $skipped = $list->getSkippedVersions();
        if ($skipped) {
            $context->log(sprintf('Plages [toutes] : %d version(s) ignorée(s), résolution impossible : %s.', \count($skipped), implode(', ', array_keys($skipped))));
        }

        return sprintf('%d paquet(s), %s ; %d à exporter', \count($artifacts), Bytes::format($size), $pending)
            .($skipped ? sprintf(' ; %d version(s) ignorée(s) (erreur de résolution, voir le journal)', \count($skipped)) : '')
            .($blocked ? sprintf(' ; %d bloqué(s) par la liste noire', \count($blocked)) : '').$security;
    }

    /**
     * Calcule les dépendances, télécharge ce qui manque ou a disparu du stockage et met à jour le contenu de la liste.
     * Utilisé aussi par la vérification d'intégrité pour retélécharger les fichiers écartés.
     *
     * @return array<int, Artifact>
     */
    public function resolveList(PackageList $list, JobContext $context): array
    {
        $resolver = null;
        foreach ($this->resolvers as $candidate) {
            if ($candidate->ecosystem() === $list->getEcosystem()) {
                $resolver = $candidate;
            }
        }
        if (!$resolver) {
            throw new \LogicException(sprintf('Aucun résolveur pour %s.', $list->getEcosystem()->value));
        }
        if ($errors = $resolver->validate($list)) {
            throw new \RuntimeException(implode("\n", $errors));
        }

        $context->log(sprintf('Liste « %s » (%s).', $list->getName(), $list->getEcosystem()->label()));

        // Motifs (« * », « ? ») : remplacés par les paquets correspondants du registre ;
        // plages « [toutes] » : par toutes les versions publiées qui respectent la contrainte
        $lines = $list->getSpecLines();
        $isSystem = \in_array($list->getEcosystem(), [\Sas\Bundle\Ecosystem::Deb, \Sas\Bundle\Ecosystem::Rpm], true);
        if (array_filter($lines, $isSystem ? VersionRange::isRange(...) : PatternExpander::needsExpansion(...))) {
            $context->report('Recherche des paquets et des versions correspondant aux motifs et aux plages');
            $expanded = $this->expander->expand($list->getEcosystem(), $lines);
            foreach ($expanded['patterns'] as $pattern => $result) {
                $matches = $result['lines'];
                $context->log(sprintf('%s « %s » : %d correspondance(s) : %s', VersionRange::isRange($pattern) ? 'Plage' : 'Motif', $pattern, \count($matches), implode(', ', \array_slice($matches, 0, 30)).(\count($matches) > 30 ? '…' : '')));
                if ($result['skipped']) {
                    $context->log(sprintf('Motif « %s » : %d paquet(s) écarté(s), sans version respectant la contrainte : %s', $pattern, \count($result['skipped']), implode(', ', $result['skipped'])));
                }
            }
            // Versions issues d'une plage : ignorées (et signalées) si leur résolution échoue
            $rangeVersions = [];
            foreach ($expanded['patterns'] as $pattern => $result) {
                if (VersionRange::isRange($pattern)) {
                    array_push($rangeVersions, ...$result['lines']);
                }
            }
            $list->useExpandedSpecs($expanded['lines'], $rangeVersions);
        }
        $this->blacklist->takeBlocked();
        $artifacts = [];
        foreach ($resolver->resolve($list, $context) as $artifact) {
            $artifacts[spl_object_id($artifact)] = $artifact;
        }
        $list->replaceArtifacts(array_values($artifacts));
        $this->em->flush();

        return $artifacts;
    }
}
