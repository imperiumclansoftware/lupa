<?php

namespace App\EventListener;

use App\Entity\Job;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * Au démarrage du worker (seul à exécuter les tâches), une tâche encore « en cours » a forcément été
 * interrompue : redémarrage du conteneur, mise à jour de l'image… Elle est marquée en échec pour ne pas
 * rester bloquée (une liste n'accepte qu'une tâche active) et pouvoir être relancée.
 */
#[AsEventListener]
final readonly class InterruptedJobsListener
{
    public function __construct(
        private JobRepository $jobs,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerStartedEvent $event): void
    {
        $interrupted = $this->jobs->findBy(['status' => Job::RUNNING]);
        foreach ($interrupted as $job) {
            $job->appendLog('Tâche interrompue : le service a redémarré pendant son exécution.');
            $job->fail('Interrompue par un redémarrage du service : relancez-la.');
            $this->logger->warning('Tâche {id} interrompue par un redémarrage, marquée en échec.', ['id' => $job->getId()]);
        }
        if ([] !== $interrupted) {
            $this->em->flush();
        }
    }
}
