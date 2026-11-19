<?php

namespace App\Security;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Journal de sécurité (canal « audit ») : connexions réussies et refusées, déconnexions, avec
 * l'identifiant et l'adresse IP ; jamais le mot de passe.
 */
final class AuditSubscriber
{
    private ?string $dummyHash = null;

    public function __construct(
        #[Target('audit')]
        private readonly LoggerInterface $audit,
        private readonly PasswordHasherFactoryInterface $hashers,
    ) {
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $this->audit->info(
            $event->getAuthenticatedToken() instanceof TwoFactorTokenInterface ? 'Mot de passe accepté, code de double authentification demandé' : 'Connexion réussie',
            self::context($event->getRequest(), $event->getUser()->getUserIdentifier()),
        );
    }

    #[AsEventListener]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $exception = $event->getException();
        $identifier = $event->getPassport()?->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class)?->getUserIdentifier();
        $throttled = $exception instanceof TooManyLoginAttemptsAuthenticationException;
        $this->audit->warning($throttled ? 'Connexion bloquée (trop de tentatives)' : 'Connexion refusée', self::context($event->getRequest(), $identifier));

        // Identifiant inconnu : on hache quand même un mot de passe, pour un temps de réponse
        // comparable à celui d'un mot de passe faux (pas d'énumération des comptes par la durée)
        for ($e = $exception; null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof UserNotFoundException) {
                $hasher = $this->hashers->getPasswordHasher(User::class);
                $this->dummyHash ??= $hasher->hash('compte-inexistant');
                $hasher->verify($this->dummyHash, 'mot-de-passe-quelconque');
                break;
            }
        }
    }

    #[AsEventListener]
    public function onLogout(LogoutEvent $event): void
    {
        $this->audit->info('Déconnexion', self::context($event->getRequest(), $event->getToken()?->getUserIdentifier()));
    }

    /** @return array<string, string|null> */
    public static function context(Request $request, ?string $user = null): array
    {
        return ['user' => $user, 'ip' => $request->getClientIp(), 'path' => $request->getPathInfo()];
    }
}
