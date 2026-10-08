<?php

namespace App\Job;

use App\Entity\ImportedBundle;
use App\Entity\Job;
use App\Entity\OciManifest;
use App\Entity\Package;
use App\Import\BundleInspector;
use App\Import\Inbox;
use App\Repository\ImportedBundleRepository;
use App\Repository\OciManifestRepository;
use App\Repository\PackageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sas\Bundle\BlobStore;
use Sas\Bundle\Ecosystem;

/**
 * Importe un bundle : vérifie la signature et la complétude AVANT d'écrire quoi que ce soit,
 * copie les fichiers en contrôlant chaque empreinte, puis publie les paquets.
 */
final readonly class ImportRunner implements JobRunner
{
    public function __construct(
        private Inbox $inbox,
        private BundleInspector $inspector,
        private BlobStore $store,
        private EntityManagerInterface $em,
        private PackageRepository $packages,
        private ImportedBundleRepository $bundles,
        private OciManifestRepository $manifests,
    ) {
    }

    public function type(): string
    {
        return 'import';
    }

    public function run(Job $job, JobContext $context): string
    {
        $payload = $job->getPayload();
        $bundle = $this->inbox->find((string) ($payload['source'] ?? ''), (string) ($payload['name'] ?? ''))
            ?? throw new \RuntimeException('Bundle introuvable : la clé USB a-t-elle été retirée ?');
        if ($bundle['missingParts']) {
            throw new \RuntimeException(sprintf('Partie(s) manquante(s) : %s. Copiez tous les fichiers %s.tar.*', implode(', ', array_map(static fn ($n) => sprintf('%03d', $n), $bundle['missingParts'])), $bundle['name']));
        }

        $context->report('Vérification de la signature');
        ['manifest' => $manifest, 'reader' => $reader, 'report' => $report] = $this->inspector->inspect($bundle['parts']);
        $context->log(sprintf('Bundle n° %d de « %s » (%s), %d paquet(s), %s.', $manifest->sequence, $manifest->collectorName, $manifest->full ? 'complet' : 'incrémental', \count($manifest->artifacts), Bytes::format($manifest->totalSize())));

        if (!$report['collector']['trusted']) {
            throw new \RuntimeException(sprintf('Collecteur « %s » non approuvé (empreinte %s). Comparez cette empreinte avec celle affichée par le collecteur, puis approuvez-le.', $manifest->collectorName, $report['collector']['fingerprint']));
        }
        if (!$report['signatureValid']) {
            throw new \RuntimeException('Signature invalide : le bundle a été modifié après sa création. Import refusé.');
        }
        if ($report['missingArtifactCount'] > 0) {
            $hint = $report['missingSequences'] ? sprintf(' Importez d\'abord le(s) bundle(s) n° %s.', implode(', ', $report['missingSequences'])) : '';
            throw new \RuntimeException(sprintf('%d paquet(s) dépendent de fichiers transportés par un bundle précédent non importé.%s Ou demandez un export complet au collecteur.', $report['missingArtifactCount'], $hint));
        }
        if ($report['missingSequences']) {
            $context->log(sprintf('Bundle(s) n° %s jamais importé(s), mais rien ne manque pour celui-ci.', implode(', ', $report['missingSequences'])));
        }

        $total = max(1, $manifest->totalSize());
        $newBlobs = \count(array_filter($manifest->blobs, fn ($b) => !$this->store->has($b['sha256'])));
        $context->log(sprintf('%d fichier(s) dont %d nouveau(x).', \count($manifest->blobs), $newBlobs));
        $reader->extractBlobs($this->store, static function (string $sha, int $position) use ($context, $total): void {
            $context->report(sprintf('Copie et vérification des fichiers : %s / %s', Bytes::format(min($position, $total)), Bytes::format($total)), (int) min(95, $position * 95 / $total));
        });

        $context->report('Publication des paquets', 96);
        $record = $this->bundles->findOneBy(['uuid' => $manifest->id]);
        if (!$record) {
            $summary = [];
            foreach ($manifest->artifacts as $artifact) {
                $summary[$artifact['ecosystem']] = ($summary[$artifact['ecosystem']] ?? 0) + 1;
            }
            $record = new ImportedBundle($manifest->id, $manifest->collectorId, $manifest->collectorName, $manifest->sequence, $manifest->full,
                $bundle['name'], \count($manifest->artifacts), $newBlobs, $manifest->totalSize(), $summary, $manifest->createdAt);
            $this->em->persist($record);
        }

        $seenManifests = [];
        foreach ($manifest->artifacts as $i => $artifact) {
            $ecosystem = Ecosystem::from($artifact['ecosystem']);
            $filename = $artifact['filename'] ?? null;
            $package = $this->packages->findOneBy(['identity' => Package::identity($ecosystem, $artifact['name'], $artifact['version'], $filename)]);
            if (!$package) {
                $package = new Package($ecosystem, $artifact['name'], $artifact['version'], $filename);
                $this->em->persist($package);
            }
            $size = array_sum(array_map($this->store->size(...), $artifact['blobs']));
            $package->update($artifact['blobs'], $size, $artifact['metadata'] ?? [], $record);

            if (Ecosystem::Docker === $ecosystem) {
                $metadata = $artifact['metadata'] ?? [];
                $entries = array_merge([['digest' => $metadata['digest'] ?? '', 'mediaType' => $metadata['mediaType'] ?? '']], $metadata['manifests'] ?? []);
                foreach ($entries as $entry) {
                    if (preg_match('/^sha256:[a-f0-9]{64}$/', $entry['digest']) && !isset($seenManifests[$entry['digest']])
                        && !$this->manifests->find($entry['digest'])) {
                        $this->em->persist(new OciManifest($entry['digest'], $entry['mediaType']));
                        $seenManifests[$entry['digest']] = true;
                    }
                }
            }
            if (0 === ($i + 1) % 200) {
                $this->em->flush();
            }
        }
        $this->em->flush();

        if ('upload' === $bundle['source'] && ($payload['deleteAfter'] ?? true)) {
            foreach ($bundle['parts'] as $part) {
                @unlink($part);
            }
        }

        return sprintf('Bundle n° %d importé : %d paquet(s), %d nouveau(x) fichier(s)', $manifest->sequence, \count($manifest->artifacts), $newBlobs);
    }
}
