<?php

namespace App\Audit;

use App\Entity\Artifact;
use App\Job\JobContext;
use App\Resolver\PypiResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Analyse de sécurité des paquets npm, Python et Composer avec OSV.dev. Les images Docker ne sont pas
 * couvertes (leurs failles sont dans les paquets du système, qui demandent un scanner d'images).
 */
final readonly class Auditor
{
    public function __construct(
        private OsvClient $osv,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param iterable<Artifact> $artifacts
     *
     * @return array{audited: int, counts: array<string, int>}
     */
    public function audit(iterable $artifacts, ?JobContext $context = null): array
    {
        // Une requête par (écosystème, nom, version) : les fichiers d'une même version Python sont groupés
        $groups = [];
        foreach ($artifacts as $artifact) {
            $ecosystem = OsvClient::ECOSYSTEMS[$artifact->getEcosystem()->value] ?? null;
            if (null === $ecosystem || str_starts_with($artifact->getName(), '_flex/')) {
                continue;
            }
            $version = 'Packagist' === $ecosystem ? ltrim($artifact->getVersion(), 'vV') : $artifact->getVersion();
            $key = $ecosystem."\0".$artifact->getName()."\0".$version;
            $groups[$key] ??= ['query' => ['ecosystem' => $ecosystem, 'name' => $artifact->getName(), 'version' => $version], 'artifacts' => []];
            $groups[$key]['artifacts'][] = $artifact;
        }
        if ([] === $groups) {
            return ['audited' => 0, 'counts' => []];
        }

        $context?->report(sprintf('Analyse de sécurité de %d paquet(s) (OSV.dev)', \count($groups)));
        $groups = array_values($groups);
        $results = $this->osv->query(array_column($groups, 'query'));

        $wanted = [];
        foreach ($results as $vulns) {
            foreach ($vulns as $v) {
                $wanted[$v['id']] = $v['modified'];
            }
        }
        $records = $this->osv->advisories($wanted, static fn (int $done, int $total) => $context?->report(sprintf('Fiches de vulnérabilités : %d / %d', $done, $total)));

        $counts = [];
        $audited = 0;
        foreach ($groups as $i => $group) {
            $vulnerabilities = $this->summarize($results[$i] ?? [], $records, $group['query']);
            $severity = null;
            foreach ($vulnerabilities as $v) {
                $severity = Severity::max($severity, $v['severity']);
            }
            foreach ($group['artifacts'] as $artifact) {
                $artifact->setAudit($vulnerabilities, $severity);
                ++$audited;
                if (null !== $severity) {
                    $counts[$severity] = ($counts[$severity] ?? 0) + 1;
                }
            }
        }
        $this->em->flush();

        return ['audited' => $audited, 'counts' => $counts];
    }

    /**
     * @param list<array{id: string, modified: string}> $found
     * @param array<string, array<string, mixed>>       $records
     * @param array{ecosystem: string, name: string, version: string} $query
     *
     * @return list<array{id: string, aliases: list<string>, summary: string, severity: string, fixed: list<string>, url: string}>
     */
    private function summarize(array $found, array $records, array $query): array
    {
        $candidates = array_values(array_filter(array_map(static fn (array $v) => $records[$v['id']] ?? null, $found)));
        // Une même faille publiée par plusieurs bases (GHSA, PYSEC, CVE) : on garde la fiche la plus précise
        usort($candidates, static fn (array $a, array $b) => (isset($b['database_specific']['severity']) <=> isset($a['database_specific']['severity']))
            ?: strcmp((string) $a['id'], (string) $b['id']));

        $kept = [];
        $seen = [];
        foreach ($candidates as $record) {
            $ids = array_merge([(string) $record['id']], array_map('strval', $record['aliases'] ?? []));
            if (array_intersect($ids, array_keys($seen))) {
                continue;
            }
            foreach ($ids as $id) {
                $seen[$id] = true;
            }
            $summary = trim((string) ($record['summary'] ?? ''));
            if ('' === $summary) {
                $summary = mb_substr(trim(strtok((string) ($record['details'] ?? ''), "\n") ?: ''), 0, 200);
            }
            $kept[] = [
                'id' => (string) $record['id'],
                'aliases' => array_values(array_filter(array_map('strval', $record['aliases'] ?? []), static fn (string $a) => str_starts_with($a, 'CVE-') || str_starts_with($a, 'GHSA-'))),
                'summary' => $summary,
                'severity' => Severity::of($record),
                'fixed' => self::fixedVersions($record, $query),
                'url' => 'https://osv.dev/vulnerability/'.rawurlencode((string) $record['id']),
            ];
        }
        usort($kept, static fn (array $a, array $b) => Severity::ORDER[$b['severity']] <=> Severity::ORDER[$a['severity']] ?: strcmp($a['id'], $b['id']));

        return $kept;
    }

    /**
     * Versions qui corrigent la faille pour ce paquet.
     *
     * @param array<string, mixed>                                    $record
     * @param array{ecosystem: string, name: string, version: string} $query
     *
     * @return list<string>
     */
    private static function fixedVersions(array $record, array $query): array
    {
        $normalize = static fn (string $n) => 'PyPI' === $query['ecosystem'] ? PypiResolver::normalize($n) : strtolower($n);
        $fixed = [];
        foreach ($record['affected'] ?? [] as $affected) {
            if (($affected['package']['ecosystem'] ?? '') !== $query['ecosystem'] || $normalize((string) ($affected['package']['name'] ?? '')) !== $normalize($query['name'])) {
                continue;
            }
            foreach ($affected['ranges'] ?? [] as $range) {
                foreach ($range['events'] ?? [] as $event) {
                    if (isset($event['fixed'])) {
                        $fixed[] = (string) $event['fixed'];
                    }
                }
            }
        }

        return array_values(array_unique($fixed));
    }
}
