<?php

namespace App\Tests\Functional;

use App\Entity\Job;
use App\Tests\MirrorWebTestCase;
use App\Tests\Support\BundleBuilder;
use Sas\Bundle\Signer;

/** Envoi d'un bundle par morceaux depuis le navigateur. */
final class UploadTest extends MirrorWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
    }

    public function testChunkedUploadWithOffsets(): void
    {
        $id = $this->start('sas-test-000001.tar', 10);

        $this->put($id, 0, 'hello');
        self::assertResponseIsSuccessful();
        self::assertSame(['received' => 5], $this->json());

        // Position déjà dépassée (morceau renvoyé deux fois) : refus avec la position réelle pour reprendre
        $this->put($id, 0, 'hello');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(5, $this->json()['received']);
        $this->put($id, 7, 'rld');
        self::assertResponseStatusCodeSame(409);

        // Fin prématurée refusée
        $this->client->request('POST', '/api/uploads/'.$id.'/complete');
        self::assertResponseStatusCodeSame(400);

        // Reprise : un nouveau « start » avec même nom et même taille renvoie la position atteinte
        self::assertSame($id, $this->start('sas-test-000001.tar', 10, 5));

        $this->put($id, 5, 'world');
        self::assertSame(['received' => 10], $this->json());

        $this->client->request('POST', '/api/uploads/'.$id.'/complete');
        self::assertResponseIsSuccessful();
        self::assertSame(['filename' => 'sas-test-000001.tar'], $this->json());
        self::assertSame('helloworld', file_get_contents($this->dir.'/uploads/sas-test-000001.tar'));
        self::assertFileDoesNotExist($this->dir.'/uploads/.partial/'.$id);
    }

    public function testInvalidUploads(): void
    {
        $this->client->jsonRequest('POST', '/api/uploads', ['filename' => '../evil.tar', 'size' => 10]);
        self::assertResponseStatusCodeSame(400);
        $this->client->jsonRequest('POST', '/api/uploads', ['filename' => 'photo.jpg', 'size' => 10]);
        self::assertResponseStatusCodeSame(400);
        $this->client->jsonRequest('POST', '/api/uploads', ['filename' => 'sas-x.tar', 'size' => 0]);
        self::assertResponseStatusCodeSame(400);

        $this->put(str_repeat('a', 32), 0, 'x');
        self::assertResponseStatusCodeSame(404);

        // Plus d'octets qu'annoncé : refusé et tronqué à la position précédente
        $id = $this->start('sas-x.tar', 4);
        $this->put($id, 0, 'trop long');
        self::assertResponseStatusCodeSame(400);
        clearstatcache();
        self::assertSame(0, filesize($this->dir.'/uploads/.partial/'.$id));
    }

    public function testUploadedBundleCanBeImportedAndIsDeletedAfterwards(): void
    {
        $keys = Signer::generateKeyPair();
        $builder = new BundleBuilder($this->dir.'/source');
        $builder->artifact('npm', 'envoye', '1.0.0', null, [$builder->blob(random_bytes(3000))], ['dist' => ['tarballName' => 'envoye-1.0.0.tgz']]);
        $name = $builder->write($this->dir.'/source', $keys);
        $bundle = (string) file_get_contents($this->dir.'/source/'.$name.'.tar');
        $this->trust($keys['publicKey']);

        $id = $this->start($name.'.tar', \strlen($bundle));
        foreach (str_split($bundle, 2048) as $i => $chunk) {
            $this->put($id, $i * 2048, $chunk);
            self::assertResponseIsSuccessful();
        }
        $this->client->request('POST', '/api/uploads/'.$id.'/complete');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/inbox');
        $items = $this->json()['items'];
        self::assertCount(1, $items);
        self::assertSame('upload', $items[0]['source']);
        self::assertTrue($items[0]['report']['importable']);

        $job = $this->import($name, 'upload');
        self::assertSame(Job::SUCCEEDED, $job->getStatus(), (string) $job->getError());
        self::assertFileDoesNotExist($this->dir.'/uploads/'.$name.'.tar');

        $this->client->request('GET', '/npm/envoye');
        self::assertResponseIsSuccessful();
    }

    private function start(string $filename, int $size, int $expectedReceived = 0): string
    {
        $this->client->jsonRequest('POST', '/api/uploads', ['filename' => $filename, 'size' => $size]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($expectedReceived, $this->json()['received']);

        return $this->json()['id'];
    }

    private function put(string $id, int $offset, string $data): void
    {
        $this->client->request('PUT', sprintf('/api/uploads/%s?offset=%d', $id, $offset), [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $data);
    }
}
