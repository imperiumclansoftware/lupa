<?php

namespace App\Controller;

use App\Dto\SetupInput;
use App\Entity\User;
use App\Entity\TrustedKey;
use Sas\Bundle\Signer;
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
        private readonly SetupToken $token,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly Security $security,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function state(): JsonResponse
    {
        if (!$this->token->isRequired()) {
            return new JsonResponse(['required' => false]);
        }
        // Crée le code s'il n'existe pas encore : il est alors lisible sur le serveur
        $this->token->code();

        return new JsonResponse(['required' => true, 'defaults' => new \stdClass()]);
    }

    #[Route('', methods: ['POST'])]
    public function install(#[MapRequestPayload] SetupInput $input): JsonResponse
    {
        if (!$this->token->isRequired()) {
            return new JsonResponse(['error' => 'L\'installation est déjà faite : connectez-vous.'], Response::HTTP_CONFLICT);
        }
        $publicKey = trim((string) $input->publicKey);
        if ('' !== $publicKey && !Signer::isPublicKey($publicKey)) {
            return new JsonResponse(['error' => 'Clé publique invalide : copiez-la depuis Rémus (Réglages › Signature des bundles).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->token->verify($input->code)) {
            return new JsonResponse(['error' => 'Code d\'installation incorrect. Il est affiché dans les journaux du serveur.'], Response::HTTP_FORBIDDEN);
        }

        $user = new User($input->username);
        $user->setPassword($this->hasher->hashPassword($user, $input->password));
        $this->em->persist($user);
        if ('' !== $publicKey) {
            $this->em->persist(new TrustedKey($publicKey, trim((string) $input->keyLabel) ?: 'Rémus'));
        }
        $this->em->flush();
        $this->token->consume();

        $this->security->login($user, 'json_login', 'main');

        return new JsonResponse(['user' => ['username' => $user->getUsername()]], Response::HTTP_CREATED);
    }
}
