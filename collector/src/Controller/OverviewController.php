<?php

namespace App\Controller;

use App\Dto\SettingsInput;
use App\Entity\Job;
use App\Identity\CollectorIdentity;
use App\Repository\ExportRepository;
use App\Repository\JobRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class OverviewController
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobRepository $jobs,
        private readonly ExportRepository $exports,
        private readonly CollectorIdentity $identity,
        private readonly string $storageDir,
        private readonly string $exportDir,
    ) {
    }

    #[Route('/overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        $ecosystems = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT ecosystem, COUNT(*) AS artifacts, COALESCE(SUM(size), 0) AS size, SUM(CASE WHEN export_id IS NULL THEN 1 ELSE 0 END) AS pending
             FROM artifact GROUP BY ecosystem'
        ) as $row) {
            $ecosystems[$row['ecosystem']] = ['artifacts' => (int) $row['artifacts'], 'size' => (int) $row['size'], 'pending' => (int) $row['pending']];
        }
        $stored = $this->db->fetchAssociative('SELECT COUNT(*) AS n, COALESCE(SUM(size), 0) AS size FROM blob') ?: ['n' => 0, 'size' => 0];
        $last = $this->exports->findOneBy([], ['sequence' => 'DESC']);

        return new JsonResponse([
            'identity' => $this->identityData(),
            'ecosystems' => (object) $ecosystems,
            'lists' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM package_list'),
            'storage' => [
                'blobs' => (int) $stored['n'],
                'size' => (int) $stored['size'],
                'free' => is_dir($this->storageDir) ? @disk_free_space($this->storageDir) ?: null : null,
            ],
            'lastExport' => $last ? Presenter::export($last, $this->exportDir) : null,
            'activeJobs' => array_map(Presenter::job(...), $this->jobs->findBy(['status' => [Job::QUEUED, Job::RUNNING]], ['id' => 'ASC'])),
            'recentJobs' => array_map(Presenter::job(...), $this->jobs->findBy([], ['id' => 'DESC'], 8)),
        ]);
    }

    #[Route('/settings', methods: ['GET'])]
    public function settings(): JsonResponse
    {
        return new JsonResponse(['identity' => $this->identityData(), 'exportDir' => $this->exportDir, 'storageDir' => $this->storageDir]);
    }

    #[Route('/settings', methods: ['PUT'])]
    public function saveSettings(#[MapRequestPayload] SettingsInput $input): JsonResponse
    {
        $this->identity->rename(trim($input->name));

        return $this->settings();
    }

    /** @return array<string, string> */
    private function identityData(): array
    {
        return [
            'id' => $this->identity->id(),
            'name' => $this->identity->name(),
            'publicKey' => $this->identity->publicKey(),
            'fingerprint' => $this->identity->fingerprint(),
        ];
    }
}
