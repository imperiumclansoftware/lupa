<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\UserView;
use App\Setup\SetupToken;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class SessionController
{
    /**
     * Utilisateur connecté, ou null : permet au front de savoir s'il doit afficher l'écran de connexion,
     * celui du code de double authentification (twoFactor) ou l'assistant d'installation.
     */
    #[Route('/api/session', methods: ['GET'])]
    public function session(Security $security, SetupToken $setup): JsonResponse
    {
        $token = $security->getToken();
        $pending = $token instanceof TwoFactorTokenInterface;
        $user = $pending ? null : $security->getUser();

        return new JsonResponse([
            'user' => $user instanceof User ? UserView::of($user) : null,
            'twoFactor' => $pending,
            // Aucun compte : le front ouvre l'assistant d'installation
            'setupRequired' => !$user && !$pending && $setup->isRequired(),
        ]);
    }

    #[Route('/api/login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('Géré par le pare-feu (json_login).');
    }

    #[Route('/api/2fa/check', methods: ['POST'])]
    public function twoFactorCheck(): never
    {
        throw new \LogicException('Géré par le pare-feu (two_factor).');
    }

    #[Route('/api/logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Géré par le pare-feu (logout).');
    }
}
