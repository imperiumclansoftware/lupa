<?php

namespace App\Job;

use App\Audit\Auditor;
use App\Entity\Job;
use App\Repository\ArtifactRepository;

/** Nouvelle analyse de sécurité (une liste, ou tout le collecteur) : de nouvelles failles sont publiées chaque jour. */
final readonly class AuditRunner implements JobRunner
{
    public function __construct(
        private Auditor $auditor,
        private ArtifactRepository $artifacts,
    ) {
    }

    public function type(): string
    {
        return 'audit';
    }

    public function run(Job $job, JobContext $context): string
    {
        $artifacts = $job->getList()?->getArtifacts()->toArray() ?? $this->artifacts->findAll();
        $result = $this->auditor->audit($artifacts, $context);

        return self::summary($result);
    }

    /** @param array{audited: int, counts: array<string, int>} $result */
    public static function summary(array $result): string
    {
        $critical = $result['counts']['critical'] ?? 0;
        $warnings = ($result['counts']['high'] ?? 0) + ($result['counts']['moderate'] ?? 0) + ($result['counts']['unknown'] ?? 0);

        return sprintf('%d paquet(s) analysé(s) : %d critique(s), %d avertissement(s)', $result['audited'], $critical, $warnings);
    }
}
