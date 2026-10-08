<?php

namespace App\MessageHandler;

use App\Entity\Job;
use App\Job\JobContext;
use App\Job\JobRunner;
use App\Message\RunJob;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunJobHandler
{
    /** @param iterable<JobRunner> $runners */
    public function __construct(
        private JobRepository $jobs,
        private EntityManagerInterface $em,
        #[AutowireIterator('app.job_runner')]
        private iterable $runners,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunJob $message): void
    {
        $job = $this->jobs->find($message->jobId);
        if (!$job || Job::QUEUED !== $job->getStatus()) {
            return;
        }

        $runner = null;
        foreach ($this->runners as $candidate) {
            if ($candidate->type() === $job->getType()) {
                $runner = $candidate;
            }
        }

        $job->start();
        $this->em->flush();
        $context = new JobContext($this->em->getConnection(), (int) $job->getId());

        try {
            if (!$runner) {
                throw new \LogicException(sprintf('Type de tâche inconnu : %s.', $job->getType()));
            }
            $summary = $runner->run($job, $context);
            $context->flushLog();
            $this->reload($job)->succeed($summary);
        } catch (\Throwable $e) {
            $this->logger->error('Tâche {id} en échec : {error}', ['id' => $job->getId(), 'error' => $e->getMessage(), 'exception' => $e]);
            $context->log('ERREUR : '.$e->getMessage());
            $context->flushLog();
            if (!$this->em->isOpen()) {
                throw $e;
            }
            $this->em->clear();
            $this->reload($job)->fail($e->getMessage());
        }
        $this->em->flush();
        $this->em->clear();
    }

    /** Recharge la tâche : le journal et la progression ont été écrits directement en base. */
    private function reload(Job $job): Job
    {
        $fresh = $this->jobs->find($job->getId());
        if ($fresh) {
            $this->em->refresh($fresh);
        }

        return $fresh ?? $job;
    }
}
