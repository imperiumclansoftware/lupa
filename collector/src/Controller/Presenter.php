<?php

namespace App\Controller;

use App\Entity\Artifact;
use App\Entity\Export;
use App\Entity\Job;
use App\Entity\PackageList;

/** Représentation JSON des entités pour le front. */
final class Presenter
{
    /** @return array<string, mixed> */
    public static function list(PackageList $list, ?Job $job = null, bool $details = false): array
    {
        $data = [
            'id' => $list->getId(),
            'name' => $list->getName(),
            'ecosystem' => $list->getEcosystem()->value,
            'specCount' => \count($list->getSpecLines()),
            'lockfileName' => $list->getLockfileName(),
            'options' => (object) $list->getOptions(),
            'artifactCount' => $list->getArtifacts()->count(),
            'size' => array_sum($list->getArtifacts()->map(static fn (Artifact $a) => $a->getSize())->toArray()),
            'pendingCount' => $list->getArtifacts()->filter(static fn (Artifact $a) => null === $a->getExport())->count(),
            'createdAt' => $list->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $list->getUpdatedAt()->format(\DATE_ATOM),
            'resolvedAt' => $list->getResolvedAt()?->format(\DATE_ATOM),
            'job' => $job ? self::job($job) : null,
        ];
        if ($details) {
            $data['specs'] = $list->getSpecs();
            $data['hasLockfile'] = null !== $list->getLockfile();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function artifact(Artifact $artifact): array
    {
        $metadata = $artifact->getMetadata();

        return [
            'id' => $artifact->getId(),
            'ecosystem' => $artifact->getEcosystem()->value,
            'name' => $artifact->getName(),
            'version' => $artifact->getVersion(),
            'filename' => $artifact->getFilename(),
            'size' => $artifact->getSize(),
            'detail' => match ($artifact->getEcosystem()->value) {
                'docker' => implode(', ', array_diff(array_column($metadata['manifests'] ?? [], 'platform'), ['attestation'])),
                'pypi' => $metadata['requiresPython'] ?? null,
                'npm', 'composer' => isset($metadata['description']) ? mb_substr((string) $metadata['description'], 0, 140) : null,
                default => null,
            },
            'exportSequence' => $artifact->getExport()?->getSequence(),
            'updatedAt' => $artifact->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

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
            'listId' => $job->getList()?->getId(),
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
    public static function export(Export $export, string $exportDir): array
    {
        $files = [];
        foreach ($export->getFiles() as $file) {
            $path = $exportDir.'/'.$file;
            $files[] = ['name' => $file, 'size' => is_file($path) ? filesize($path) : null, 'present' => is_file($path)];
        }

        return [
            'id' => $export->getId(),
            'sequence' => $export->getSequence(),
            'full' => $export->isFull(),
            'baseName' => $export->getBaseName(),
            'files' => $files,
            'size' => $export->getSize(),
            'artifactCount' => $export->getArtifactCount(),
            'blobCount' => $export->getBlobCount(),
            'summary' => (object) $export->getSummary(),
            'maxPartSize' => $export->getMaxPartSize(),
            'createdAt' => $export->getCreatedAt()->format(\DATE_ATOM),
            'job' => $export->getJob() ? self::job($export->getJob()) : null,
        ];
    }
}
