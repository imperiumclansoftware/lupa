<?php

namespace App\Controller;

use App\Dto\PasswordChangeInput;
use App\Dto\PasswordConfirmInput;
use App\Dto\TotpCodeInput;
use App\Entity\User;
use App\Security\AuditSubscriber;
use App\Security\TotpEnrollment;
use App\Security\UserView;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Son propre compte, quel que soit son rôle : mot de passe et double authentification (ADR 0009). */
#[Route('/api/account')]
final class AccountController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TotpEnrollment $enrollment,
        #[Target('audit')]
        private readonly LoggerInterface $audit,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(UserView::of($user));
    }

    /** Visite guidée d'une page vue (fermée ou terminée) : elle ne s'ouvrira plus d'elle-même. */
    #[Route('/tours', methods: ['POST'])]
    public function tourSeen(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        $tour = $request->toArray()['tour'] ?? null;
        if (!\is_string($tour) || !preg_match('/^[a-z0-9-]{1,40}$/', $tour)) {
            return new JsonResponse(['error' => 'Visite inconnue.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $user->markTourSeen($tour);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** Toutes les visites guidées s'afficheront de nouveau. */
    #[Route('/tours', methods: ['DELETE'])]
    public function resetTours(#[CurrentUser] User $user): JsonResponse
    {
        $user->resetTours();
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/password', methods: ['POST'])]
    public function password(#[CurrentUser] User $user, #[MapRequestPayload] PasswordChangeInput $input, Request $request): JsonResponse
    {
        $this->checkPassword($user, $input->current, $request);
        $user->setPassword($this->hasher->hashPassword($user, $input->password));
        $this->em->flush();
        $this->audit->info('Mot de passe changé', AuditSubscriber::context($request, $user->getUsername()));

        return new JsonResponse(UserView::of($user));
    }

    /** Nouveau secret à scanner ; actif seulement après confirmation par un code (enable). */
    #[Route('/2fa/setup', methods: ['POST'])]
    public function setup(#[CurrentUser] User $user, #[MapRequestPayload] PasswordConfirmInput $input, Request $request): JsonResponse
    {
        $this->checkPassword($user, $input->password, $request);

        return new JsonResponse($this->enrollment->start($user, $request->getSession()));
    }

    #[Route('/2fa/enable', methods: ['POST'])]
    public function enable(#[CurrentUser] User $user, #[MapRequestPayload] TotpCodeInput $input, Request $request): JsonResponse
    {
        $codes = $this->enrollment->confirm($user, $request->getSession(), $input->code);
        if (null === $codes) {
            return new JsonResponse(['error' => 'Code incorrect : vérifiez l\'heure du téléphone et réessayez.'], 422);
        }
        $this->em->flush();
        $this->audit->info('Double authentification activée', AuditSubscriber::context($request, $user->getUsername()));

        return new JsonResponse(UserView::of($user) + ['codes' => $codes]);
    }

    #[Route('/2fa/backup-codes', methods: ['POST'])]
    public function backupCodes(#[CurrentUser] User $user, #[MapRequestPayload] PasswordConfirmInput $input, Request $request): JsonResponse
    {
        $this->checkPassword($user, $input->password, $request);
        $secret = $user->getTotpAuthenticationConfiguration()?->getSecret();
        if (null === $secret) {
            return new JsonResponse(['error' => 'La double authentification n\'est pas activée.'], 409);
        }
        $codes = TotpEnrollment::backupCodes();
        $user->enableTotp($secret, $codes);
        $this->em->flush();
        $this->audit->info('Codes de secours régénérés', AuditSubscriber::context($request, $user->getUsername()));

        return new JsonResponse(UserView::of($user) + ['codes' => $codes]);
    }

    #[Route('/2fa/disable', methods: ['POST'])]
    public function disable(#[CurrentUser] User $user, #[MapRequestPayload] PasswordConfirmInput $input, Request $request): JsonResponse
    {
        $this->checkPassword($user, $input->password, $request);
        $user->disableTotp();
        $this->em->flush();
        $this->audit->notice('Double authentification désactivée', AuditSubscriber::context($request, $user->getUsername()));

        return new JsonResponse(UserView::of($user));
    }

    private function checkPassword(User $user, string $password, Request $request): void
    {
        if (!$this->hasher->isPasswordValid($user, $password)) {
            $this->audit->warning('Mot de passe actuel refusé', AuditSubscriber::context($request, $user->getUsername()));

            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Mot de passe actuel incorrect.');
        }
    }
}
