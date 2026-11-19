<?php

namespace App\Controller;

use App\Dto\ImportInput;
use App\Entity\Job;
use App\Entity\TrustedKey;
use App\Import\BundleInspector;
use App\Import\Inbox;
use App\Job\JobLauncher;
use App\Repository\JobRepository;
use App\Repository\TrustedKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sas\Bundle\Signer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Bundles présents sur la clé USB ou envoyés depuis le navigateur, et leur import. */
#[Route('/api/inbox')]
final class InboxController
{
    public function __construct(
        private readonly Inbox $inbox,
        private readonly BundleInspector $inspector,
        private readonly TrustedKeyRepository $keys,
        private readonly JobRepository $jobs,
        private readonly EntityManagerInterface $em,
        private readonly JobLauncher $launcher,
        private readonly Security $security,
        #[Target('audit')]
        private readonly LoggerInterface $audit,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $items = [];
        foreach ($this->inbox->list() as $bundle) {
            $item = [
                'source' => $bundle['source'],
                'name' => $bundle['name'],
                'parts' => array_map(basename(...), $bundle['parts']),
                'size' => $bundle['size'],
                'missingParts' => $bundle['missingParts'],
                'modifiedAt' => date(\DATE_ATOM, $bundle['modifiedAt']),
                'report' => null,
                'error' => null,
            ];
            try {
                $item['report'] = $this->inspector->inspect($bundle['parts'])['report'];
            } catch (\Throwable $e) {
                $item['error'] = $e->getMessage();
            }
            $items[] = $item;
        }

        $sources = [];
        foreach ($this->inbox->sources() as $source => $dir) {
            $sources[] = ['id' => $source, 'path' => $dir, 'available' => $this->inbox->available($source)];
        }

        return new JsonResponse(['sources' => $sources, 'items' => $items, 'unreadable' => $this->inbox->unreadable()]);
    }

    /**
     * Lance l'import. Avec "trust": true, la clé du collecteur est approuvée au préalable : le front ne
     * l'envoie qu'après que l'utilisateur a comparé l'empreinte affichée avec celle du collecteur.
     */
    #[Route('/import', methods: ['POST'])]
    public function import(#[MapRequestPayload] ImportInput $input): JsonResponse
    {
        [$source, $name] = [$input->source, $input->name];
        $bundle = $this->inbox->find($source, $name) ?? throw new NotFoundHttpException('Bundle introuvable.');

        $running = $this->jobs->findOneBy(['type' => 'import', 'status' => [Job::QUEUED, Job::RUNNING]]);
        if ($running) {
            return new JsonResponse(['error' => 'Un import est déjà en cours.'], Response::HTTP_CONFLICT);
        }

        if ($input->trust) {
            // Approuver une clé, c'est accepter tout ce que ce collecteur enverra : administrateurs seulement
            if (!$this->security->isGranted('ROLE_ADMIN')) {
                throw new AccessDeniedHttpException('Seul un administrateur peut approuver un nouveau collecteur.');
            }
            $manifest = $this->inspector->inspect($bundle['parts'])['manifest'];
            if (null === $input->fingerprint || !hash_equals(Signer::fingerprint($manifest->collectorPublicKey), $input->fingerprint)) {
                return new JsonResponse(['error' => 'L\'empreinte confirmée ne correspond pas à celle du bundle.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (!$this->keys->findOneBy(['publicKey' => $manifest->collectorPublicKey])) {
                $this->em->persist(new TrustedKey($manifest->collectorPublicKey, $manifest->collectorName));
                $this->em->flush();
                $this->audit->notice('Collecteur approuvé à l\'import', ['user' => $this->security->getUser()?->getUserIdentifier(), 'collector' => $manifest->collectorName, 'fingerprint' => $input->fingerprint]);
            }
        }

        $job = $this->launcher->launch('import', sprintf('Import de %s', $name), ['source' => $source, 'name' => $name, 'deleteAfter' => $input->deleteAfter]);

        return new JsonResponse(Presenter::job($job), Response::HTTP_ACCEPTED);
    }

    /** Supprime un bundle envoyé depuis le navigateur (jamais les fichiers de la clé USB). */
    #[Route('/upload/{name}', methods: ['DELETE'], requirements: ['name' => '[A-Za-z0-9._-]+'])]
    public function delete(string $name): Response
    {
        $bundle = $this->inbox->find('upload', $name) ?? throw new NotFoundHttpException('Bundle introuvable.');
        foreach ($bundle['parts'] as $part) {
            unlink($part);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
