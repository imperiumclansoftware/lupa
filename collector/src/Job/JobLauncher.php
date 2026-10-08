<?php

namespace App\Job;

use App\Entity\Job;
use App\Entity\PackageList;
use App\Message\RunJob;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class JobLauncher
{
    public function __construct(
        private EntityManagerInterface $em,
        private JobRepository $jobs,
        private MessageBusInterface $bus,
    ) {
    }

    /** Crée la tâche et la confie au worker ; une seule tâche active par liste. */
    public function launch(string $type, string $title, ?PackageList $list = null, ?callable $beforeDispatch = null): Job
    {
        if ($list) {
            $active = $this->jobs->findOneBy(['list' => $list, 'status' => [Job::QUEUED, Job::RUNNING]]);
            if ($active) {
                return $active;
            }
        }
        $job = new Job($type, $title, $list);
        $this->em->persist($job);
        if ($beforeDispatch) {
            $beforeDispatch($job);
        }
        $this->em->flush();
        $this->bus->dispatch(new RunJob((int) $job->getId()));

        return $job;
    }
}
