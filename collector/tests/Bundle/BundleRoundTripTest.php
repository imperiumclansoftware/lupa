<?php

namespace App\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use Sas\Bundle\BlobStore;
use Sas\Bundle\BundleFiles;
use Sas\Bundle\BundleReader;
use Sas\Bundle\BundleWriter;
use Sas\Bundle\IntegrityException;
use Sas\Bundle\Manifest;
use Sas\Bundle\Signer;

final class BundleRoundTripTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/usb', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testSplitBundleRoundTrip(): void
    {
        $source = new BlobStore($this->dir.'/source');
        $a = $source->putString(random_bytes(3000));
        $b = $source->putString(random_bytes(70000));
        $keys = Signer::generateKeyPair();

        $manifest = $this->manifest($keys['publicKey'], [$a, $b]);
        $files = BundleWriter::write($this->dir.'/usb/'.BundleFiles::baseName('Bureau Été', 3), $manifest, $keys['secretKey'], $source, 10240);

        self::assertGreaterThan(5, \count($files));
        self::assertStringEndsWith('sas-bureau-ete-000003.tar.001', $files[0]);

        $found = BundleFiles::discover($this->dir.'/usb');
        self::assertSame($files, $found['sas-bureau-ete-000003']['parts']);
        self::assertSame([], $found['sas-bureau-ete-000003']['missingParts']);

        $reader = new BundleReader($found['sas-bureau-ete-000003']['parts']);
        self::assertSame(3, $reader->manifest()->sequence);
        self::assertTrue($reader->verifySignature($keys['publicKey']));
        self::assertFalse($reader->verifySignature(Signer::generateKeyPair()['publicKey']));

        $target = new BlobStore($this->dir.'/target');
        $imported = $reader->extractBlobs($target);
        self::assertEqualsCanonicalizing([$a['sha256'], $b['sha256']], $imported);
        self::assertSame($source->read($b['sha256']), $target->read($b['sha256']));
    }

    public function testSingleFileAndMissingPartDetection(): void
    {
        $source = new BlobStore($this->dir.'/source');
        $a = $source->putString(random_bytes(50000));
        $keys = Signer::generateKeyPair();

        $files = BundleWriter::write($this->dir.'/usb/one', $this->manifest($keys['publicKey'], [$a]), $keys['secretKey'], $source);
        self::assertSame([$this->dir.'/usb/one.tar'], $files);

        $parts = BundleWriter::write($this->dir.'/usb/split', $this->manifest($keys['publicKey'], [$a]), $keys['secretKey'], $source, 8192);
        unlink(array_pop($parts));
        $reader = new BundleReader($parts);
        $reader->manifest();
        $this->expectException(IntegrityException::class);
        $reader->extractBlobs(new BlobStore($this->dir.'/target'));
    }

    public function testTamperedBlobIsRejected(): void
    {
        $source = new BlobStore($this->dir.'/source');
        $a = $source->putString(str_repeat('a', 2000));
        $keys = Signer::generateKeyPair();
        [$file] = BundleWriter::write($this->dir.'/usb/x', $this->manifest($keys['publicKey'], [$a]), $keys['secretKey'], $source);

        $data = file_get_contents($file);
        file_put_contents($file, str_replace(str_repeat('a', 100), str_repeat('b', 100), $data));

        $reader = new BundleReader([$file]);
        self::assertTrue($reader->verifySignature($keys['publicKey']));
        $this->expectException(IntegrityException::class);
        $reader->extractBlobs(new BlobStore($this->dir.'/target'));
    }

    /** @param list<array{sha256: string, size: int}> $blobs */
    private function manifest(string $publicKey, array $blobs): Manifest
    {
        return new Manifest('id', 'collector', 'Bureau', $publicKey, 3, new \DateTimeImmutable(), false, [
            ['ecosystem' => 'npm', 'name' => 'demo', 'version' => '1.0.0', 'blobs' => array_column($blobs, 'sha256')],
        ], $blobs);
    }
}
