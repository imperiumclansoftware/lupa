<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class SessionController
{
    /** Utilisateur connecté, ou null : permet au front de savoir s'il doit afficher l'écran de connexion. */
    #[Route('/api/session', methods: ['GET'])]
    public function session(#[CurrentUser] ?User $user): JsonResponse
    {
        return new JsonResponse(['user' => $user ? ['username' => $user->getUsername()] : null]);
    }

    #[Route('/api/login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('Géré par le pare-feu (json_login).');
    }

    #[Route('/api/logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Géré par le pare-feu (logout).');
    }
}
