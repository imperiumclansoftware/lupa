<?php

namespace App\Tests\Functional;

use App\Security\Role;
use App\Tests\MirrorWebTestCase;

/** Documents PDF : réservés aux comptes connectés, politiques de sécurité aux administrateurs. */
final class DocumentsTest extends MirrorWebTestCase
{
    public function testAnonymousVisitorGetsNothing(): void
    {
        $this->client->request('GET', '/api/documents');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/documents/conditions-utilisation');
        self::assertResponseStatusCodeSame(401);
    }

    public function testReaderGetsTermsAndPrivacyOnly(): void
    {
        $this->login(Role::Reader);
        $this->client->request('GET', '/api/documents');
        self::assertSame(['conditions-utilisation', 'confidentialite'], array_column($this->json()['items'], 'id'));

        $this->client->request('GET', '/api/documents/confidentialite');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('attachment; filename=lupa-confidentialite.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF-', $this->body());

        $this->client->request('GET', '/api/documents/securite-detaillee');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/documents/securite');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdministratorGetsAllDocuments(): void
    {
        $this->login();
        $this->client->request('GET', '/api/documents');
        $items = $this->json()['items'];
        self::assertSame(['conditions-utilisation', 'confidentialite', 'securite', 'securite-detaillee'], array_column($items, 'id'));
        self::assertSame([false, false, true, true], array_column($items, 'restricted'));

        $this->client->request('GET', '/api/documents/securite-detaillee');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $this->client->request('GET', '/api/documents/inconnu');
        self::assertResponseStatusCodeSame(404);
    }
}
