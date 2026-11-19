<?php

namespace App\Controller;

use App\Dto\SetupInput;
use App\Entity\User;
use App\Identity\CollectorIdentity;
use App\Setup\SetupToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Assistant d'installation : création du premier compte, protégée par le code d'installation. */
#[Route('/api/setup')]
final class SetupController
{
    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Target('audit')]
        private readonly \Psr\Log\LoggerInterface $audit,
        private readonly SetupToken $token,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly Security $security,
        private readonly CollectorIdentity $identity,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function state(): JsonResponse
    {
        if (!$this->token->isRequired()) {
            return new JsonResponse(['required' => false]);
        }
        return new JsonResponse(['required' => true, 'defaults' => ['name' => $this->identity->name()]]);
    }

    #[Route('', methods: ['POST'])]
    public function install(#[MapRequestPayload] SetupInput $input, \Symfony\Component\HttpFoundation\Request $request): JsonResponse
    {
        if (!$this->token->isRequired()) {
            return new JsonResponse(['error' => 'L\'installation est déjà faite : connectez-vous.'], Response::HTTP_CONFLICT);
        }
        if (!$this->token->verify($input->code)) {
            $this->audit->warning('Code d\'installation incorrect', \App\Security\AuditSubscriber::context($request, $input->username));

            return new JsonResponse(['error' => 'Code d\'installation incorrect. Il est affiché dans les journaux du serveur au démarrage ; app:setup:code en crée un nouveau.'], Response::HTTP_FORBIDDEN);
        }

        $user = new User($input->username);
        $user->setPassword($this->hasher->hashPassword($user, $input->password));
        $this->em->persist($user);
        $this->em->flush();
        $this->token->consume();
        $this->audit->info('Compte initial créé par l\'assistant d\'installation', \App\Security\AuditSubscriber::context($request, $user->getUsername()));

        if (null !== $input->name && '' !== trim($input->name)) {
            $this->identity->rename(trim($input->name));
        }

        $this->security->login($user, 'json_login', 'main');

        return new JsonResponse(['user' => ['username' => $user->getUsername()]], Response::HTTP_CREATED);
    }
}
