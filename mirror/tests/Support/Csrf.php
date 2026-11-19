<?php

namespace App\Tests\Support;

use App\Security\CsrfSubscriber;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Obtient le jeton CSRF de la session (comme le front) et l'envoie dans toutes les requêtes suivantes. */
final class Csrf
{
    public static function prime(KernelBrowser $client): string
    {
        $client->request('GET', '/api/session');
        $token = (string) $client->getCookieJar()->get(CsrfSubscriber::COOKIE)?->getValue();
        $client->setServerParameter('HTTP_X_XSRF_TOKEN', $token);

        return $token;
    }
}
