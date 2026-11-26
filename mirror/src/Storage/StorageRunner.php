<?php

namespace App\Storage;

use App\Entity\Job;
use App\Job\Bytes;
use App\Job\JobContext;
use App\Job\JobRunner;
use Sas\Bundle\BlobMigration;
use Sas\Bundle\BlobStore;
use Sas\Bundle\StorageSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Migration du stockage : copie vers S3 (ou retour sur disque), empreinte vérifiée, puis bascule si aucun
 * fichier n'a échoué. Les fichiers d'origine sont conservés ; relancer la tâche reprend où elle s'est arrêtée.
 */
final readonly class StorageRunner implements JobRunner
{
    public function __construct(
        private StorageSettings $settings,
        private BlobStore $store,
        private BlobMigration $migration,
        #[Autowire('%env(STORAGE_DIR)%/tmp')]
        private string $tempDir,
    ) {
    }

    public function type(): string
    {
        return 'storage';
    }

    public function run(Job $job, JobContext $context): string
    {
        $target = 's3' === $this->settings->active() ? 'local' : 's3';
        $from = $this->store->backend();
        $to = 's3' === $target ? $this->settings->s3Backend() : $this->store->localBackend();
        $context->log(sprintf('Copie de %s vers %s.', $from->describe(), $to->describe()));

        $stats = $this->migration->run(
            $from,
            $to,
            $this->tempDir,
            static fn (int $done, int $total, string $step) => $context->report($step, $total > 0 ? intdiv(100 * $done, $total) : 100),
            $context->log(...),
        );
        $context->log(sprintf('%d fichier(s) copié(s) (%s), %d déjà présent(s), %d en erreur.', $stats['copied'], Bytes::format($stats['bytes']), $stats['skipped'], $stats['failed']));
        if ($stats['failed'] > 0) {
            throw new \RuntimeException(sprintf('%d fichier(s) non copié(s) : le stockage reste %s. Corrigez la cause (journal), puis relancez : les fichiers déjà copiés ne le seront pas de nouveau.', $stats['failed'], 's3' === $target ? 'sur disque' : 'sur S3'));
        }
        $this->settings->activate($target);
        $context->log('Stockage actif : '.$this->store->backend()->describe().'. Les fichiers d\'origine sont conservés.');

        return sprintf('%d fichier(s) copié(s), %d déjà présent(s) ; stockage actif : %s', $stats['copied'], $stats['skipped'], 's3' === $target ? 'S3' : 'disque');
    }
}
