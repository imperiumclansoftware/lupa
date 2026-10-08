<?php

namespace App\Job;

use App\Entity\Artifact;
use App\Entity\Blob;
use App\Entity\Job;
use App\Identity\CollectorIdentity;
use App\Repository\ArtifactRepository;
use App\Repository\BlobRepository;
use App\Repository\ExportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sas\Bundle\BlobStore;
use Sas\Bundle\BundleFiles;
use Sas\Bundle\BundleWriter;
use Sas\Bundle\Manifest;

/**
 * Écrit un bundle dans le dossier d'export (la clé USB) : soit tout ce qui n'a jamais été exporté
 * (incrémental), soit tout le contenu du collecteur (complet, pour un nouveau miroir).
 */
final readonly class ExportRunner implements JobRunner
{
    public function __construct(
        private ExportRepository $exports,
        private ArtifactRepository $artifacts,
        private BlobRepository $blobs,
        private EntityManagerInterface $em,
        private BlobStore $store,
        private CollectorIdentity $identity,
        private string $exportDir,
    ) {
    }

    public function type(): string
    {
        return 'export';
    }

    public function run(Job $job, JobContext $context): string
    {
        $export = $this->exports->findOneBy(['job' => $job]) ?? throw new \RuntimeException('Export introuvable.');
        try {
            return $this->write($export, $context);
        } catch (\Throwable $e) {
            // Numéro libéré : le miroir ne doit pas croire qu'un bundle manque
            $this->em->remove($export);
            $this->em->flush();
            throw $e;
        }
    }

    private function write(\App\Entity\Export $export, JobContext $context): string
    {
        $context->report('Sélection du contenu');
        /** @var list<Artifact> $artifacts */
        $artifacts = $export->isFull()
            ? $this->artifacts->findBy([], ['ecosystem' => 'ASC', 'name' => 'ASC'])
            : $this->artifacts->findBy(['export' => null], ['ecosystem' => 'ASC', 'name' => 'ASC']);
        if ([] === $artifacts) {
            throw new \RuntimeException('Rien de nouveau à exporter : tout a déjà été transporté. Lancez la mise à jour d\'une liste ou faites un export complet.');
        }

        /** @var list<Blob> $blobs */
        $blobs = $export->isFull() ? $this->blobs->findAll() : $this->blobs->findBy(['export' => null]);
        $needed = [];
        foreach ($artifacts as $artifact) {
            foreach ($artifact->getBlobs() as $sha) {
                $needed[$sha] = true;
            }
        }
        // Seuls les fichiers utiles aux artefacts exportés (un fichier orphelin reste au collecteur)
        $blobs = array_values(array_filter($blobs, static fn (Blob $b) => isset($needed[$b->getSha256()])));
        foreach ($blobs as $blob) {
            if (!$this->store->has($blob->getSha256())) {
                throw new \RuntimeException(sprintf('Fichier %s absent du stockage : relancez la mise à jour des listes.', $blob->getSha256()));
            }
        }

        $total = array_sum(array_map(static fn (Blob $b) => $b->getSize(), $blobs));
        if (!is_dir($this->exportDir) && !@mkdir($this->exportDir, 0o770, true)) {
            throw new \RuntimeException(sprintf('Dossier d\'export %s inaccessible (clé USB non montée ?).', $this->exportDir));
        }
        $free = @disk_free_space($this->exportDir);
        if (false !== $free && $free < $total + (16 << 20)) {
            throw new \RuntimeException(sprintf('Espace insuffisant dans le dossier d\'export : %s nécessaires, %s libres.', Bytes::format($total), Bytes::format((int) $free)));
        }

        $summary = [];
        foreach ($artifacts as $artifact) {
            $summary[$artifact->getEcosystem()->value] = ($summary[$artifact->getEcosystem()->value] ?? 0) + 1;
        }
        $context->log(sprintf('%d artefact(s), %d fichier(s), %s.', \count($artifacts), \count($blobs), Bytes::format($total)));

        $manifest = new Manifest(
            $export->getUuid(),
            $this->identity->id(),
            $this->identity->name(),
            $this->identity->publicKey(),
            $export->getSequence(),
            $export->getCreatedAt(),
            $export->isFull(),
            array_map(static fn (Artifact $a) => $a->toManifest(), $artifacts),
            array_map(static fn (Blob $b) => ['sha256' => $b->getSha256(), 'size' => $b->getSize()], $blobs),
        );

        $baseName = BundleFiles::baseName($this->identity->name(), $export->getSequence());
        $files = BundleWriter::write(
            $this->exportDir.'/'.$baseName,
            $manifest,
            $this->identity->secretKey(),
            $this->store,
            $export->getMaxPartSize(),
            static fn (int $done, int $all) => $context->report(sprintf('Écriture : %s / %s', Bytes::format($done), Bytes::format($all)), $all > 0 ? (int) ($done * 100 / $all) : null),
        );

        $size = array_sum(array_map(filesize(...), $files));
        foreach ($artifacts as $artifact) {
            $artifact->getExport() ?? $artifact->setExport($export);
        }
        foreach ($blobs as $blob) {
            $blob->getExport() ?? $blob->setExport($export);
        }
        $export->complete($baseName, array_map(basename(...), $files), $size, \count($artifacts), \count($blobs), $summary);
        $this->em->flush();

        return sprintf('%s : %d fichier(s) sur le support, %s', $baseName, \count($files), Bytes::format($size));
    }
}
