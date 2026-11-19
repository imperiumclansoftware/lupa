<?php

namespace App\Security;

use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\Http\Authentication\AuthenticationRequiredHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Deuxième étape de connexion (code TOTP ou code de secours), en JSON pour le front.
 *
 * Après cinq codes faux, la session est fermée : il faut redonner le mot de passe, dont les tentatives
 * sont limitées (login_throttling). Un code à six chiffres ne peut donc pas être deviné par essais.
 */
final class TwoFactorHandler implements AuthenticationRequiredHandlerInterface, AuthenticationSuccessHandlerInterface, AuthenticationFailureHandlerInterface
{
    private const string FAILURES = '_2fa_failures';
    private const int MAX_FAILURES = 5;

    public function __construct(
        #[Target('audit')]
        private readonly LoggerInterface $audit,
        private readonly TokenStorageInterface $tokens,
    ) {
    }

    public function onAuthenticationRequired(Request $request, TokenInterface $token): Response
    {
        return new JsonResponse(['error' => 'Code de double authentification requis.', 'twoFactor' => true], Response::HTTP_UNAUTHORIZED);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $request->getSession()->remove(self::FAILURES);
        $this->audit->info('Double authentification réussie', AuditSubscriber::context($request, $token->getUserIdentifier()));

        return new JsonResponse(['username' => $token->getUserIdentifier()]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $session = $request->getSession();
        $previous = $session->get(self::FAILURES, 0);
        $failures = (\is_int($previous) ? $previous : 0) + 1;
        $this->audit->warning('Code de double authentification refusé', AuditSubscriber::context($request) + ['failures' => $failures]);
        if ($failures >= self::MAX_FAILURES) {
            // Sans effacer le jeton, le pare-feu le réécrirait dans la nouvelle session en fin de requête
            $this->tokens->setToken(null);
            $session->invalidate();

            return new JsonResponse(['error' => 'Trop de codes incorrects : reconnectez-vous.', 'twoFactor' => false], Response::HTTP_UNAUTHORIZED);
        }
        $session->set(self::FAILURES, $failures);

        return new JsonResponse(['error' => 'Code incorrect.', 'twoFactor' => true], Response::HTTP_UNAUTHORIZED);
    }
}
