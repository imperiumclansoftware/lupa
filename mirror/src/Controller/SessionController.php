<?php

namespace App\Controller;

use App\Entity\User;
use App\Setup\SetupToken;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class SessionController
{
    /** Utilisateur connecté, ou null : permet au front de savoir s'il doit afficher l'écran de connexion. */
    #[Route('/api/session', methods: ['GET'])]
    public function session(#[CurrentUser] ?User $user, SetupToken $setup): JsonResponse
    {
        return new JsonResponse([
            'user' => $user ? ['username' => $user->getUsername()] : null,
            // Aucun compte : le front ouvre l'assistant d'installation
            'setupRequired' => !$user && $setup->isRequired(),
        ]);
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
