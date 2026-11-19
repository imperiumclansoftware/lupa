<?php

namespace App\Controller;

use App\Entity\Job;
use App\Repository\JobRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/jobs')]
final class JobController
{
    public function __construct(private readonly JobRepository $jobs)
    {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map(Presenter::job(...), $this->jobs->findBy([], ['id' => 'DESC'], 50)));
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => Requirement::UUID_V7])]
    public function show(Job $job): JsonResponse
    {
        return new JsonResponse(Presenter::job($job, true));
    }
}
