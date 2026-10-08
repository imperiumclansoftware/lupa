<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/** Erreurs de l'API au format {"error": "…"} lisible par le front (messages de validation compris). */
#[AsEventListener(priority: -10)]
final class ApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }
        $exception = $event->getThrowable();
        if (!$exception instanceof HttpExceptionInterface) {
            return;
        }

        $message = $exception->getMessage();
        if ($exception->getPrevious() instanceof ValidationFailedException) {
            $messages = [];
            foreach ($exception->getPrevious()->getViolations() as $violation) {
                $messages[] = $violation->getMessage();
            }
            $message = implode("\n", $messages);
        } elseif ($exception->getStatusCode() >= 500 || '' === $message || str_contains($message, '"App\\')) {
            $message = match ($exception->getStatusCode()) {
                404 => 'Introuvable.',
                403 => 'Accès refusé.',
                default => 'Requête invalide.',
            };
        }

        $event->setResponse(new JsonResponse(['error' => $message], $exception->getStatusCode(), $exception->getHeaders()));
    }
}
