<?php

namespace App\Job;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

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
        public readonly Uuid $jobId,
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
            [mb_substr($step, 0, 255), null === $progress ? null : max(0, min(100, $progress)), $this->jobId->toBinary()],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::BINARY],
        );
        $this->flushLog(true);
    }

    /**
     * Rapport structuré de la tâche, écrit en base comme le journal (la tâche est rechargée à la fin).
     *
     * @param array<string, mixed> $report
     */
    public function saveReport(array $report): void
    {
        $this->db->executeStatement(
            'UPDATE job SET report = ? WHERE id = ?',
            [json_encode($report, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), $this->jobId->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY],
        );
    }

    public function flushLog(bool $force = true): void
    {
        if ('' === $this->pendingLog || (!$force && \strlen($this->pendingLog) < 2000)) {
            return;
        }
        $this->db->executeStatement('UPDATE job SET log = log || ? WHERE id = ?', [$this->pendingLog, $this->jobId->toBinary()], [ParameterType::STRING, ParameterType::BINARY]);
        $this->pendingLog = '';
    }
}
