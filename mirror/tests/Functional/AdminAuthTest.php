<?php

namespace App\Tests\Functional;

use App\Tests\MirrorWebTestCase;

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
        self::assertSame(['user' => null, 'setupRequired' => true], $this->json());
    }

    public function testJsonLoginOpensSession(): void
    {
        $this->createUser('admin', 'motdepasse-test');

        $this->client->jsonRequest('POST', '/api/login', ['username' => 'admin', 'password' => 'motdepasse-test']);
        self::assertResponseIsSuccessful();
        self::assertSame(['username' => 'admin'], $this->json());

        $this->client->request('GET', '/api/session');
        self::assertSame(['user' => ['username' => 'admin'], 'setupRequired' => false], $this->json());

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
}
