<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;

/** Réglages › À propos : version de l'application (comptes connectés seulement, comme toute l'API). */
#[Route('/api/about')]
final class AboutController
{
    public function __construct(
        #[Autowire('%env(APP_VERSION)%')]
        private readonly string $version,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'application' => 'Rémus',
            'role' => 'collecteur Lupa',
            'version' => $this->version,
            'symfony' => Kernel::VERSION,
            'php' => \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION.'.'.\PHP_RELEASE_VERSION,
            'publisher' => 'Imperium Clan Software',
            'license' => 'MIT',
            'repository' => 'https://github.com/imperiumclansoftware/lupa',
        ]);
    }
}
