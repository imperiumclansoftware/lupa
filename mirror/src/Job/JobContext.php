<?php

namespace App\Job;

use Doctrine\DBAL\Connection;

/**
 * Progression et journal d'une tâche, écrits directement en base (hors unité de travail Doctrine)
 * pour être visibles immédiatement depuis l'interface pendant que la tâche tourne.
 */
final class JobContext
{
    private float $lastReport = 0;
    private string $pendingLog = '';

    public function __construct(
        private readonly Connection $db,
        public readonly int $jobId,
    ) {
    }

    public function log(string $line): void
    {
        $this->pendingLog .= sprintf("[%s] %s\n", date('H:i:s'), $line);
        $this->flushLog(false);
    }

    /** @param int|null $progress 0 à 100 */
    public function report(string $step, ?int $progress = null): void
    {
        $now = microtime(true);
        // Au plus deux écritures par seconde
        if ($now - $this->lastReport < 0.5 && null !== $progress && $progress < 100) {
            return;
        }
        $this->lastReport = $now;
        $this->db->executeStatement(
            'UPDATE job SET step = ?, progress = ? WHERE id = ?',
            [mb_substr($step, 0, 255), null === $progress ? null : max(0, min(100, $progress)), $this->jobId],
        );
        $this->flushLog(true);
    }

    public function flushLog(bool $force = true): void
    {
        if ('' === $this->pendingLog || (!$force && \strlen($this->pendingLog) < 2000)) {
            return;
        }
        $this->db->executeStatement('UPDATE job SET log = log || ? WHERE id = ?', [$this->pendingLog, $this->jobId]);
        $this->pendingLog = '';
    }
}
