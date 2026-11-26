<?php

namespace App\Tests\Functional;

use App\Tests\MirrorWebTestCase;

/** Visites guidées vues, retenues sur le compte. */
final class ToursTest extends MirrorWebTestCase
{
    public function testSeenTours(): void
    {
        $this->login();
        $this->client->jsonRequest('POST', '/api/account/tours', ['tour' => 'romulus-import']);
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('POST', '/api/account/tours', ['tour' => 'TROP/LONG']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', '/api/session');
        self::assertSame(['romulus-import'], $this->json()['user']['seenTours']);
        $this->client->request('DELETE', '/api/account/tours');
        $this->client->request('GET', '/api/session');
        self::assertSame([], $this->json()['user']['seenTours']);
    }
}
