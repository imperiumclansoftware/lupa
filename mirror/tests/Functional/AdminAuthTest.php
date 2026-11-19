<?php

namespace App\Tests\Functional;

use App\Tests\MirrorWebTestCase;
use App\Tests\Support\Csrf;

/** L'API d'administration exige une session ; les registres restent publics. */
final class AdminAuthTest extends MirrorWebTestCase
{
    public function testApiRequiresLogin(): void
    {
        foreach (['/api/overview', '/api/inbox', '/api/keys', '/api/jobs'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(401, $path);
            self::assertResponseHeaderSame('Content-Type', 'application/json');
            self::assertSame(['error' => 'Authentification requise.'], $this->json());
        }

        $this->client->request('GET', '/api/session');
        self::assertResponseIsSuccessful();
        self::assertSame(['user' => null, 'twoFactor' => false, 'setupRequired' => true], $this->json());
    }

    public function testJsonLoginOpensSession(): void
    {
        $this->createUser('admin', 'motdepasse-test');
        Csrf::prime($this->client);

        $this->client->jsonRequest('POST', '/api/login', ['username' => 'admin', 'password' => 'motdepasse-test']);
        self::assertResponseIsSuccessful();
        self::assertSame(['username' => 'admin'], $this->json());

        $this->client->request('GET', '/api/session');
        $session = $this->json();
        self::assertSame('admin', $session['user']['username']);
        self::assertSame('admin', $session['user']['role']);
        self::assertFalse($session['user']['twoFactor']);
        self::assertFalse($session['setupRequired']);

        $this->client->request('GET', '/api/overview');
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('ecosystems', $this->json());

        $this->client->request('POST', '/api/logout');
        $this->client->request('GET', '/api/overview');
        self::assertResponseStatusCodeSame(401);
    }

    public function testRegistriesArePublic(): void
    {
        $this->client->request('GET', '/v2/');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/composer/packages.json');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/pypi/simple/');
        self::assertResponseIsSuccessful();
    }

    public function testCsrfTokenIsRequired(): void
    {
        $this->createUser('admin', 'motdepasse-test');
        // Connexion sans jeton : refusée (login CSRF), même sans session
        $this->client->jsonRequest('POST', '/api/login', ['username' => 'admin', 'password' => 'motdepasse-test']);
        self::assertResponseStatusCodeSame(403);

        $token = Csrf::prime($this->client);
        self::assertSame(64, \strlen($token));
        $cookie = $this->client->getCookieJar()->get('XSRF-TOKEN');
        self::assertFalse($cookie->isHttpOnly());
        self::assertSame('strict', strtolower((string) $cookie->getSameSite()));
        $this->client->jsonRequest('POST', '/api/login', ['username' => 'admin', 'password' => 'motdepasse-test']);
        self::assertResponseIsSuccessful();

        // Session connectée : jeton faux ou absent refusé
        $this->client->setServerParameter('HTTP_X_XSRF_TOKEN', str_repeat('0', 64));
        $this->client->jsonRequest('POST', '/api/keys', ['publicKey' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->client->setServerParameter('HTTP_X_XSRF_TOKEN', '');
        $this->client->request('POST', '/api/logout');
        self::assertResponseStatusCodeSame(403);

        // Bon jeton : la requête atteint le contrôleur (ici, refusée pour clé invalide)
        $this->client->setServerParameter('HTTP_X_XSRF_TOKEN', $token);
        $this->client->jsonRequest('POST', '/api/keys', ['publicKey' => 'x']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testExpiredSessionStillAnswers401(): void
    {
        // Sans session ni jeton, une requête protégée reçoit 401 (le front renvoie vers la connexion)
        $this->client->jsonRequest('POST', '/api/keys', ['publicKey' => 'x']);
        self::assertResponseStatusCodeSame(401);
    }
}
