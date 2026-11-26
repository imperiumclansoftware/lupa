<?php

namespace App\Tests\Unit\System;

use App\Tests\Support\FakeS3;
use PHPUnit\Framework\TestCase;
use Sas\Bundle\BlobStore;
use Sas\Bundle\S3BlobBackend;

/** Moteur S3 contre un serveur simulé : envoi vérifié, lecture, plages d'octets, préfixe, liste, déplacement. */
final class S3BlobBackendTest extends TestCase
{
    private string $dir;
    private FakeS3 $s3;
    private BlobStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-s3-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->s3 = new FakeS3();
        $backend = S3BlobBackend::create(['endpoint' => 'http://s3.test', 'region' => '', 'bucket' => 'paquets', 'prefix' => 'lupa/remus', 'pathStyle' => true, 'accessKey' => 'cle'], 'secret', $this->dir.'/tmp', $this->s3->client());
        $this->store = new BlobStore($this->dir, static fn () => $backend);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testStoreAndRead(): void
    {
        $blob = $this->store->putString('contenu du paquet');
        $sha = $blob['sha256'];

        self::assertArrayHasKey('paquets/lupa/remus/sha256/'.substr($sha, 0, 2).'/'.$sha, $this->s3->objects);
        self::assertTrue($this->store->has($sha));
        self::assertSame(17, $this->store->size($sha));
        self::assertSame('contenu du paquet', $this->store->read($sha));
        self::assertSame('du pa', stream_get_contents($this->store->stream($sha, 8, 5)));
        self::assertSame('contenu du paquet', $this->store->withFile($sha, 'file_get_contents'));
        self::assertSame([], glob($this->dir.'/tmp/*'), 'copies temporaires supprimées');
        self::assertSame(['sha256/'.substr($sha, 0, 2).'/'.$sha], iterator_to_array($this->store->backend()->keys('sha256'), false));

        // Déjà présent : rien n'est renvoyé
        $this->s3->failing = array_keys($this->s3->objects);
        $this->store->putString('contenu du paquet');

        self::assertNotNull($this->store->isolate($sha));
        self::assertFalse($this->store->has($sha));
        self::assertCount(1, array_filter(array_keys($this->s3->objects), static fn (string $k) => str_starts_with($k, 'paquets/lupa/remus/corrupt/'.$sha)));
    }

    public function testServerRejectsAlteredUpload(): void
    {
        $backend = $this->store->backend();
        $file = $this->dir.'/fichier';
        file_put_contents($file, 'contenu réel');

        $this->expectExceptionMessage('BadDigest');
        $backend->store($file, 'sha256/aa/'.str_repeat('a', 64), str_repeat('a', 64));
    }

    public function testMissingObject(): void
    {
        self::assertFalse($this->store->has(str_repeat('b', 64)));
    }
}
