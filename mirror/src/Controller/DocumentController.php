<?php

namespace App\Controller;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Documents de Lupa (PDF générés depuis docs/legal) : conditions d'utilisation et confidentialité pour tout
 * compte connecté, politiques de sécurité pour les administrateurs seulement. Servis depuis un dossier hors
 * de public/ : aucune adresse publique ne les expose.
 */
#[Route('/api/documents')]
final class DocumentController
{
    /** Identifiant => titre, description, rôle minimal */
    public const array DOCUMENTS = [
        'conditions-utilisation' => ['Conditions d\'utilisation', 'Règles d\'usage de Lupa et partage des responsabilités.', 'ROLE_READER'],
        'confidentialite' => ['Politique de confidentialité', 'Données personnelles traitées, destinataires, durées et droits.', 'ROLE_READER'],
        'securite' => ['Politique de sécurité', 'Principes, rôles, règles d\'exploitation et gestion des incidents.', 'ROLE_ADMIN'],
        'securite-detaillee' => ['Politique de sécurité détaillée', 'Pile logicielle, mécanismes de sécurité et risques résiduels.', 'ROLE_ADMIN'],
    ];

    public function __construct(
        private readonly Security $security,
        #[Autowire('%env(DOCUMENTS_DIR)%')]
        private readonly string $directory,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $items = [];
        foreach (self::DOCUMENTS as $id => [$title, $description, $role]) {
            $file = $this->file($id);
            if ($this->security->isGranted($role) && null !== $file) {
                $items[] = [
                    'id' => $id,
                    'title' => $title,
                    'description' => $description,
                    'restricted' => 'ROLE_ADMIN' === $role,
                    'size' => (int) filesize($file),
                    'updatedAt' => date(\DATE_ATOM, (int) filemtime($file)),
                ];
            }
        }

        return new JsonResponse(['items' => $items]);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '[a-z-]+'])]
    public function download(string $id): BinaryFileResponse
    {
        [, , $role] = self::DOCUMENTS[$id] ?? throw new NotFoundHttpException('Document inconnu.');
        if (!$this->security->isGranted($role)) {
            throw new AccessDeniedHttpException('Document réservé aux administrateurs.');
        }
        $file = $this->file($id) ?? throw new NotFoundHttpException('Document absent de cette installation.');

        $response = new BinaryFileResponse($file, 200, ['Content-Type' => 'application/pdf'], false);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'lupa-'.$id.'.pdf');
        // Document parfois confidentiel : jamais conservé par un cache intermédiaire
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function file(string $id): ?string
    {
        $file = rtrim($this->directory, '/').'/'.$id.'.pdf';

        return is_file($file) ? $file : null;
    }
}
