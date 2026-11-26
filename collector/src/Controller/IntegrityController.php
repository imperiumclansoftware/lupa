<?php

namespace App\Controller;

use App\Entity\Job;
use App\Job\JobLauncher;
use App\Repository\JobRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;

/** Vérification d'intégrité du stockage (Réglages) : tâche en cours et dernier rapport. */
#[Route('/api/integrity')]
final class IntegrityController
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobLauncher $launcher,
        private readonly LockFactory $locks,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $active = $this->active();
        $last = $this->jobs->findOneBy(['type' => 'integrity', 'status' => [Job::SUCCEEDED, Job::FAILED]], ['id' => 'DESC']);

        return new JsonResponse([
            'job' => $active ? Presenter::job($active) : null,
            'last' => $last ? Presenter::job($last, true) : null,
        ]);
    }

    /** Lance la vérification ; une seule à la fois (renvoie celle en cours). */
    #[Route('', methods: ['POST'])]
    public function check(): JsonResponse
    {
        $lock = $this->locks->createLock('job:integrity');
        $lock->acquire(true);
        try {
            $job = $this->active() ?? $this->launcher->launch('integrity', 'Vérification de l\'intégrité des paquets');
        } finally {
            $lock->release();
        }

        return new JsonResponse(Presenter::job($job), Response::HTTP_ACCEPTED);
    }

    private function active(): ?Job
    {
        return $this->jobs->findOneBy(['type' => 'integrity', 'status' => [Job::QUEUED, Job::RUNNING]]);
    }
}
