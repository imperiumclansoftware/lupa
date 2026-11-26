<?php

namespace App\Tests\Functional;

use App\Tests\MirrorWebTestCase;

/** Réglages › Stockage de Romulus : paramètres S3 enregistrés, secret jamais renvoyé, migration refusée sans eux. */
final class StorageApiTest extends MirrorWebTestCase
{
    public function testSettings(): void
    {
        $this->login();
        $this->client->jsonRequest('POST', '/api/storage/migrate', []);
        self::assertResponseStatusCodeSame(422);

        $config = ['endpoint' => 'http://minio:9000', 'region' => '', 'bucket' => 'romulus', 'prefix' => '', 'pathStyle' => true, 'accessKey' => 'romulus'];
        $this->client->jsonRequest('PUT', '/api/storage/s3', ['secretKey' => 'secret-minio'] + $config); // gitleaks:allow
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/storage');
        $state = $this->json();
        self::assertSame(['local', 'romulus', true], [$state['active'], $state['s3']['bucket'], $state['s3']['hasSecret']]);
        self::assertStringNotContainsString('secret-minio', $this->body());
    }
}
