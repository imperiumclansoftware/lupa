<?php

namespace App\Controller;

use App\Dto\ExportInput;
use App\Entity\Export;
use App\Entity\Job;
use App\Job\JobLauncher;
use App\Repository\ExportRepository;
use App\Repository\JobRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/exports')]
final class ExportController
{
    public function __construct(
        private readonly ExportRepository $exports,
        private readonly JobRepository $jobs,
        private readonly EntityManagerInterface $em,
        private readonly JobLauncher $launcher,
        private readonly Connection $db,
        private readonly string $exportDir,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'exportDir' => $this->exportDir,
            'freeSpace' => is_dir($this->exportDir) ? @disk_free_space($this->exportDir) ?: null : null,
            'pending' => $this->pending(false),
            'full' => $this->pending(true),
            'items' => array_map(fn (Export $e) => Presenter::export($e, $this->exportDir), $this->exports->findBy([], ['sequence' => 'DESC'])),
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function create(#[MapRequestPayload] ExportInput $input): JsonResponse
    {
        $running = $this->jobs->findOneBy(['type' => 'export', 'status' => [Job::QUEUED, Job::RUNNING]]);
        if ($running) {
            return new JsonResponse(['error' => 'Un export est déjà en cours.'], Response::HTTP_CONFLICT);
        }
        if (0 === $this->pending($input->full)['artifacts']) {
            return new JsonResponse(['error' => $input->full ? 'Le collecteur est vide.' : 'Rien de nouveau à exporter.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $sequence = 1 + (int) $this->db->fetchOne('SELECT COALESCE(MAX(sequence), 0) FROM export');
        $export = new Export($sequence, $input->full, null === $input->maxPartMiB ? null : $input->maxPartMiB << 20);
        $job = $this->launcher->launch('export', sprintf('Export n° %d (%s)', $sequence, $input->full ? 'complet' : 'nouveautés'), null, function (Job $job) use ($export): void {
            $export->setJob($job);
            $this->em->persist($export);
        });

        return new JsonResponse(Presenter::job($job), Response::HTTP_ACCEPTED);
    }

    /** Téléchargement d'un fichier du bundle par le navigateur (alternative à l'écriture directe sur la clé). */
    #[Route('/{id}/files/{name}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(Export $export, string $name): BinaryFileResponse
    {
        if (!\in_array($name, $export->getFiles(), true) || !is_file($path = $this->exportDir.'/'.$name)) {
            throw new NotFoundHttpException('Fichier absent du dossier d\'export.');
        }
        // Type fixé : sans symfony/mime, BinaryFileResponse ne peut pas le deviner
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => 'application/x-tar']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);

        return $response;
    }

    /** Supprime les fichiers du dossier d'export (ex. après copie sur la clé) ; l'historique est conservé. */
    #[Route('/{id}/files', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteFiles(Export $export): JsonResponse
    {
        foreach ($export->getFiles() as $name) {
            $path = $this->exportDir.'/'.$name;
            if (is_file($path)) {
                unlink($path);
            }
        }

        return new JsonResponse(Presenter::export($export, $this->exportDir));
    }

    /**
     * Ce que contiendrait le prochain export.
     *
     * @return array{artifacts: int, blobs: int, size: int}
     */
    private function pending(bool $full): array
    {
        $where = $full ? '' : ' WHERE export_id IS NULL';
        $artifacts = (int) $this->db->fetchOne('SELECT COUNT(*) FROM artifact'.$where);
        $blobs = $this->db->fetchAssociative('SELECT COUNT(*) AS n, COALESCE(SUM(size), 0) AS size FROM blob'.$where) ?: ['n' => 0, 'size' => 0];

        return ['artifacts' => $artifacts, 'blobs' => (int) $blobs['n'], 'size' => (int) $blobs['size']];
    }
}
