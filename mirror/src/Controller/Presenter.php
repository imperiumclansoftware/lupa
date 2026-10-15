<?php

namespace App\Controller;

use App\Entity\ImportedBundle;
use App\Entity\Job;
use App\Entity\Package;
use App\Entity\QuarantineEntry;
use App\Entity\TrustedKey;

/** Représentation JSON des entités pour le front. */
final class Presenter
{
    /** @return array<string, mixed> */
    public static function job(Job $job, bool $withLog = false): array
    {
        $data = [
            'id' => $job->getId(),
            'type' => $job->getType(),
            'title' => $job->getTitle(),
            'status' => $job->getStatus(),
            'progress' => $job->getProgress(),
            'step' => $job->getStep(),
            'error' => $job->getError(),
            'payload' => (object) $job->getPayload(),
            'createdAt' => $job->getCreatedAt()->format(\DATE_ATOM),
            'startedAt' => $job->getStartedAt()?->format(\DATE_ATOM),
            'finishedAt' => $job->getFinishedAt()?->format(\DATE_ATOM),
        ];
        if ($withLog) {
            $data['log'] = $job->getLog();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function bundle(ImportedBundle $bundle): array
    {
        return [
            'id' => $bundle->getId(),
            'uuid' => $bundle->getUuid(),
            'collectorId' => $bundle->getCollectorId(),
            'collectorName' => $bundle->getCollectorName(),
            'sequence' => $bundle->getSequence(),
            'full' => $bundle->isFull(),
            'source' => $bundle->getSource(),
            'artifactCount' => $bundle->getArtifactCount(),
            'newBlobCount' => $bundle->getNewBlobCount(),
            'size' => $bundle->getSize(),
            'summary' => (object) $bundle->getSummary(),
            'createdAt' => $bundle->getCreatedAt()->format(\DATE_ATOM),
            'importedAt' => $bundle->getImportedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function key(TrustedKey $key): array
    {
        return [
            'id' => $key->getId(),
            'label' => $key->getLabel(),
            'publicKey' => $key->getPublicKey(),
            'fingerprint' => $key->getFingerprint(),
            'createdAt' => $key->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function package(Package $package, ?QuarantineEntry $quarantine = null): array
    {
        $metadata = $package->getMetadata();

        return [
            'id' => $package->getId(),
            'version' => $package->getVersion(),
            'filename' => $package->getFilename(),
            'size' => $package->getSize(),
            'detail' => match ($package->getEcosystem()->value) {
                'docker' => implode(', ', array_diff(array_column($metadata['manifests'] ?? [], 'platform'), ['attestation'])),
                'pypi' => isset($metadata['requiresPython']) ? 'Python '.$metadata['requiresPython'] : null,
                default => null,
            },
            'digest' => $metadata['digest'] ?? null,
            // Analyse de sécurité faite par Rémus avant l'export
            'severity' => $metadata['security']['severity'] ?? null,
            'vulnerabilities' => $metadata['security']['vulnerabilities'] ?? [],
            'auditedAt' => $metadata['security']['auditedAt'] ?? null,
            'quarantine' => $quarantine ? self::quarantine($quarantine) : null,
            'bundleSequence' => $package->getBundle()?->getSequence(),
            'createdAt' => $package->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $package->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function quarantine(QuarantineEntry $entry): array
    {
        return [
            'id' => $entry->getId(),
            'ecosystem' => $entry->getEcosystem()->value,
            'name' => $entry->getName(),
            'version' => $entry->getVersion(),
            'reason' => $entry->getReason(),
            'collectorName' => $entry->getCollectorName(),
            'revision' => $entry->getRevision(),
            'createdAt' => $entry->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
