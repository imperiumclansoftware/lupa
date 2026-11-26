<?php

namespace App\Tests\Functional;

use App\Registry\Files;
use App\Tests\Support\FakeS3;
use PHPUnit\Framework\TestCase;
use Sas\Bundle\BlobStore;
use Sas\Bundle\S3BlobBackend;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/** Romulus sert les fichiers d'un bucket S3 lui-même (les postes n'y accèdent pas), plages d'octets comprises. */
final class S3FilesTest extends TestCase
{
    private string $dir;
    private BlobStore $store;
    private RequestStack $requests;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-s3files-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $backend = S3BlobBackend::create(['endpoint' => 'http://s3.test', 'region' => '', 'bucket' => 'miroir', 'prefix' => '', 'pathStyle' => true, 'accessKey' => 'cle'], 'secret', $this->dir.'/tmp', (new FakeS3())->client());
        $this->store = new BlobStore($this->dir, static fn () => $backend);
        $this->requests = new RequestStack();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testServeWithRanges(): void
    {
        $sha = $this->store->putString('0123456789')['sha256'];

        [$status, $body, $response] = $this->serve($sha);
        self::assertSame([200, '0123456789', '10', 'bytes'], [$status, $body, $response->headers->get('Content-Length'), $response->headers->get('Accept-Ranges')]);
        self::assertSame('"'.$sha.'"', $response->getEtag());

        [$status, $body, $response] = $this->serve($sha, 'bytes=2-5');
        self::assertSame([206, '2345', 'bytes 2-5/10'], [$status, $body, $response->headers->get('Content-Range')]);

        [$status, $body] = $this->serve($sha, 'bytes=-3');
        self::assertSame([206, '789'], [$status, $body]);

        [$status] = $this->serve($sha, 'bytes=20-30');
        self::assertSame(416, $status);
    }

    /** @return array{int, string, Response} */
    private function serve(string $sha, ?string $range = null): array
    {
        $request = Request::create('/fichier');
        if (null !== $range) {
            $request->headers->set('Range', $range);
        }
        $this->requests->push($request);
        $response = (new Files($this->store, '', $this->requests))->serve($sha);
        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();
        $this->requests->pop();

        return [$response->getStatusCode(), $body, $response];
    }
}
