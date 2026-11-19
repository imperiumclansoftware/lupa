<?php

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Protection CSRF de l'API (ADR 0007) : jeton de 256 bits par session, déposé dans le cookie
 * XSRF-TOKEN et attendu dans l'en-tête X-XSRF-TOKEN (withXsrfConfiguration d'Angular) pour toute
 * requête qui modifie des données, connexion comprise.
 */
final class CsrfSubscriber
{
    public const string COOKIE = 'XSRF-TOKEN';
    public const string HEADER = 'X-XSRF-TOKEN';
    private const string SESSION_KEY = '_xsrf_token';
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];
    /** Requêtes protégées même sans session : connexion (login CSRF) et création du premier compte */
    private const array ALWAYS = ['/api/login', '/api/setup'];

    public function __construct(
        #[Target('audit')]
        private readonly LoggerInterface $audit,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 10)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isApi($request) || \in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return;
        }
        $session = $request->hasSession() ? $request->getSession() : null;
        // Sans utilisateur connecté, le pare-feu répond 401 (le front renvoie vers la connexion) ;
        // la connexion et l'installation exigent quand même le jeton (login CSRF)
        if (!\in_array($request->getPathInfo(), self::ALWAYS, true) && !$session?->has('_security_main')) {
            return;
        }
        $expected = $session?->get(self::SESSION_KEY);
        $received = $request->headers->get(self::HEADER);
        if (!\is_string($expected) || !\is_string($received) || !hash_equals($expected, $received)) {
            $this->audit->warning('Requête refusée : jeton CSRF absent ou invalide', AuditSubscriber::context($request));
            $event->setResponse(new JsonResponse(['error' => 'Jeton de sécurité absent ou expiré : rechargez la page.'], JsonResponse::HTTP_FORBIDDEN));
        }
    }

    /** Toute réponse de l'API garantit la présence du jeton (créé à la première visite, renouvelé avec la session). */
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isApi($request) || !$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        $token = $session->get(self::SESSION_KEY);
        if (!\is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $session->set(self::SESSION_KEY, $token);
        }
        if ($request->cookies->get(self::COOKIE) !== $token) {
            // Lisible par le front (non HttpOnly) : il ne vaut rien sans le cookie de session
            $event->getResponse()->headers->setCookie(
                Cookie::create(self::COOKIE, $token, 0, '/', null, $request->isSecure(), false, false, Cookie::SAMESITE_STRICT),
            );
        }
    }

    private static function isApi(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/');
    }
}
