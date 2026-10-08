<?php

namespace App\Job;

use App\Entity\Job;
use App\Message\RunJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class JobLauncher
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function launch(string $type, string $title, array $payload = []): Job
    {
        $job = new Job($type, $title, $payload);
        $this->em->persist($job);
        $this->em->flush();
        $this->bus->dispatch(new RunJob((int) $job->getId()));

        return $job;
    }
}
