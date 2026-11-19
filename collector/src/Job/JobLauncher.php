<?php

namespace App\Job;

use App\Entity\Job;
use App\Entity\PackageList;
use App\Message\RunJob;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class JobLauncher
{
    public function __construct(
        private EntityManagerInterface $em,
        private JobRepository $jobs,
        private MessageBusInterface $bus,
        private LockFactory $locks,
    ) {
    }

    /** Crée la tâche et la confie au worker ; une seule tâche active par liste. */
    public function launch(string $type, string $title, ?PackageList $list = null, ?callable $beforeDispatch = null): Job
    {
        if (!$list) {
            return $this->create($type, $title, null, $beforeDispatch);
        }
        // Vérification et création en une étape : deux clics simultanés ne lancent qu'une tâche
        $lock = $this->locks->createLock('job:list:'.$list->getId()->toRfc4122());
        $lock->acquire(true);
        try {
            return $this->jobs->findOneBy(['list' => $list, 'status' => [Job::QUEUED, Job::RUNNING]])
                ?? $this->create($type, $title, $list, $beforeDispatch);
        } finally {
            $lock->release();
        }
    }

    private function create(string $type, string $title, ?PackageList $list, ?callable $beforeDispatch): Job
    {
        $job = new Job($type, $title, $list);
        $this->em->persist($job);
        if ($beforeDispatch) {
            $beforeDispatch($job);
        }
        $this->em->flush();
        $this->bus->dispatch(new RunJob($job->getId()->toRfc4122()));

        return $job;
    }
}
