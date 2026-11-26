<?php

namespace App\Tests\Functional;

use App\Tests\MirrorWebTestCase;

/** Réglages › À propos : version de Romulus, réservée aux comptes connectés. */
final class AboutTest extends MirrorWebTestCase
{
    public function testVersion(): void
    {
        $this->client->request('GET', '/api/about');
        self::assertResponseStatusCodeSame(401);

        $this->login();
        $this->client->request('GET', '/api/about');
        self::assertResponseIsSuccessful();
        $about = $this->json();
        self::assertSame(['Romulus', 'dev', 'Imperium Clan Software'], [$about['application'], $about['version'], $about['publisher']]);
    }
}
