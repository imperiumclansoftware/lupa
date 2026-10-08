<?php

namespace App\Tests\Functional;

use App\Entity\ImportedBundle;
use App\Entity\Job;
use App\Entity\Package;
use App\Tests\MirrorWebTestCase;
use App\Tests\Support\BundleBuilder;
use Sas\Bundle\Signer;

/** La clé USB est un vecteur d'attaque : rien ne doit être publié si le bundle n'est pas intègre et approuvé. */
final class ImportSafetyTest extends MirrorWebTestCase
{
    /** @var array{secretKey: string, publicKey: string} */
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = Signer::generateKeyPair();
        $this->login();
    }

    public function testUntrustedCollectorIsRefused(): void
    {
        $name = $this->npmBundle()->write($this->dir.'/inbox', $this->keys);

        $job = $this->import($name);

        self::assertSame(Job::FAILED, $job->getStatus());
        self::assertStringContainsString('non approuvé', (string) $job->getError());
        self::assertStringContainsString(Signer::fingerprint($this->keys['publicKey']), (string) $job->getError());
        $this->assertNothingPublished();
    }

    public function testSignatureFromAnotherKeyIsRefused(): void
    {
        // Le manifeste annonce une clé approuvée mais il est signé avec une autre clé
        $forger = Signer::generateKeyPair();
        $name = $this->npmBundle()->write($this->dir.'/inbox', $this->keys, signingKey: $forger['secretKey']);
        $this->trust($this->keys['publicKey']);

        $job = $this->import($name);

        self::assertSame(Job::FAILED, $job->getStatus());
        self::assertStringContainsString('Signature invalide', (string) $job->getError());
        $this->assertNothingPublished();
    }

    public function testTamperedBlobIsRefused(): void
    {
        $content = random_bytes(5000);
        $builder = new BundleBuilder($this->dir.'/source');
        $sha = $builder->blob($content);
        $builder->artifact('npm', 'altere', '1.0.0', null, [$sha], ['dist' => ['tarballName' => 'altere-1.0.0.tgz']]);
        $name = $builder->write($this->dir.'/inbox', $this->keys);
        $this->trust($this->keys['publicKey']);

        // Un octet modifié au milieu du fichier, après la signature du manifeste
        $tar = $this->dir.'/inbox/'.$name.'.tar';
        $bytes = (string) file_get_contents($tar);
        $offset = strpos($bytes, $content);
        self::assertNotFalse($offset);
        $offset += 2500;
        $bytes[$offset] = \chr(\ord($bytes[$offset]) ^ 0xFF);
        file_put_contents($tar, $bytes);

        $job = $this->import($name);

        self::assertSame(Job::FAILED, $job->getStatus());
        self::assertStringContainsString('Empreinte incorrecte', (string) $job->getError());
        $this->assertNothingPublished();
        self::assertFileDoesNotExist(sprintf('%s/storage/sha256/%s/%s', $this->dir, substr($sha, 0, 2), $sha));
    }

    public function testIncrementalBundleWithMissingBlobIsRefused(): void
    {
        // Bundle n° 2 incrémental : le fichier de « ancien » a été transporté par le n° 1, jamais importé ici
        $builder = new BundleBuilder($this->dir.'/source');
        $builder->artifact('npm', 'ancien', '1.0.0', null, [hash('sha256', 'transporté par le bundle 1')]);
        $builder->artifact('npm', 'nouveau', '1.0.0', null, [$builder->blob(random_bytes(100))]);
        $name = $builder->write($this->dir.'/inbox', $this->keys, sequence: 2, full: false);
        $this->trust($this->keys['publicKey']);

        // L'inspection (écran « Import ») signale déjà le problème
        $this->client->request('GET', '/api/inbox');
        self::assertResponseIsSuccessful();
        $report = $this->json()['items'][0]['report'];
        self::assertTrue($report['signatureValid']);
        self::assertFalse($report['importable']);
        self::assertSame(['npm ancien 1.0.0'], $report['missingArtifacts']);
        self::assertSame([1], $report['missingSequences']);

        $job = $this->import($name);

        self::assertSame(Job::FAILED, $job->getStatus());
        self::assertStringContainsString('1 paquet(s) dépendent de fichiers transportés par un bundle précédent', (string) $job->getError());
        self::assertStringContainsString('bundle(s) n° 1', (string) $job->getError());
        $this->assertNothingPublished();
    }

    public function testIncrementalBundleAfterFullImportSucceeds(): void
    {
        $this->trust($this->keys['publicKey']);
        $first = new BundleBuilder($this->dir.'/source');
        $shared = $first->blob('fichier partagé');
        $first->artifact('npm', 'ancien', '1.0.0', null, [$shared]);
        $job = $this->import($first->write($this->dir.'/inbox', $this->keys, sequence: 1));
        self::assertSame(Job::SUCCEEDED, $job->getStatus(), (string) $job->getError());

        // Le n° 2 référence le fichier déjà importé sans le transporter
        $second = new BundleBuilder($this->dir.'/source2');
        $second->artifact('npm', 'ancien', '1.0.1', null, [$shared]);
        $job = $this->import($second->write($this->dir.'/inbox', $this->keys, sequence: 2, full: false));

        self::assertSame(Job::SUCCEEDED, $job->getStatus(), (string) $job->getError());
        self::assertSame(2, $this->countRows(Package::class));
        self::assertSame(2, $this->countRows(ImportedBundle::class));
    }

    private function npmBundle(): BundleBuilder
    {
        $builder = new BundleBuilder($this->dir.'/source');
        $builder->artifact('npm', 'demo', '1.0.0', null, [$builder->blob(random_bytes(800))], ['dist' => ['tarballName' => 'demo-1.0.0.tgz']]);

        return $builder;
    }

    private function assertNothingPublished(): void
    {
        self::assertSame(0, $this->countRows(Package::class));
        self::assertSame(0, $this->countRows(ImportedBundle::class));
        $this->client->request('GET', '/npm/demo');
        self::assertResponseStatusCodeSame(404);
    }
}
