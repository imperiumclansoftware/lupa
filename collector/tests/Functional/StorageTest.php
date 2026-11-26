<?php

namespace App\Tests\Functional;

use App\Entity\Job;
use App\Entity\User;
use App\Job\JobContext;
use App\Security\Role;
use App\Source\SecretBox;
use App\Storage\StorageRunner;
use App\Tests\Support\Csrf;
use App\Tests\Support\FakeS3;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Sas\Bundle\BlobMigration;
use Sas\Bundle\BlobStore;
use Sas\Bundle\StorageSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\NilUuid;

/** Réglages › Stockage : paramètres S3, migration disque → S3 (et retour) contre un serveur S3 simulé. */
final class StorageTest extends WebTestCase
{
    private const array CONFIG = ['endpoint' => 'http://s3.test', 'region' => 'eu-west-3', 'bucket' => 'paquets', 'prefix' => 'lupa', 'pathStyle' => true, 'accessKey' => 'lupa'];

    private KernelBrowser $client;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-storage-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/storage', 0o777, true);
        $_ENV['STORAGE_DIR'] = $_SERVER['STORAGE_DIR'] = $this->dir.'/storage';
        putenv('STORAGE_DIR='.$this->dir.'/storage');
        $this->client = static::createClient();
        $em = $this->em();
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = OFF');
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testSettingsApi(): void
    {
        $this->login(Role::Operator);
        $this->client->request('GET', '/api/storage');
        self::assertResponseStatusCodeSame(403);

        $this->login(Role::Admin, 'admin');
        foreach ([
            [['endpoint' => 'pas-une-adresse'], 'Point d\'accès invalide'],
            [['endpoint' => 'https://s3.example.com/chemin'], 'Point d\'accès invalide'],
            [['bucket' => 'UN'], 'Nom de bucket invalide'],
            [['prefix' => '../x'], 'Préfixe invalide'],
            [['accessKey' => ''], 'identifiant de la clé'],
            [[], 'Saisissez la clé secrète'],
        ] as [$change, $error]) {
            $this->client->jsonRequest('PUT', '/api/storage/s3', $change + self::CONFIG);
            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString($error, (string) $this->json()['error']);
        }
        $this->client->jsonRequest('PUT', '/api/storage/s3', ['secretKey' => 'tres-secret'] + self::CONFIG); // gitleaks:allow
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/storage');
        $state = $this->json();
        self::assertSame('local', $state['active']);
        self::assertSame(['endpoint' => 'http://s3.test', 'region' => 'eu-west-3', 'bucket' => 'paquets', 'prefix' => 'lupa', 'pathStyle' => true, 'accessKey' => 'lupa', 'hasSecret' => true], $state['s3']);
        self::assertStringNotContainsString('tres-secret', (string) $this->client->getResponse()->getContent());
        // Secret chiffré en base
        self::assertStringNotContainsString('tres-secret', (string) $this->em()->getConnection()->fetchOne("SELECT value FROM setting WHERE name = 'storage.s3'"));
    }

    public function testMigrationToS3AndBack(): void
    {
        $s3 = new FakeS3();
        $settings = new StorageSettings($this->em()->getConnection(), static::getContainer()->get(SecretBox::class), $this->dir.'/storage/tmp', $s3->client());
        $settings->saveS3(self::CONFIG, 'secret');
        $store = new BlobStore($this->dir.'/storage', $settings->backend(...));
        $blobs = array_map(static fn (string $c) => $store->putString($c)['sha256'], ['paquet a', 'paquet b', 'paquet c']);

        // Panne d'un envoi : rien ne bascule
        $s3->failing = ['paquets/lupa/sha256/'.substr($blobs[1], 0, 2).'/'.$blobs[1]];
        try {
            $this->runner($settings, $store)->run(new Job('storage', 'Migration'), $this->context());
            self::fail('Migration incomplète acceptée.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('1 fichier(s) non copié(s)', $e->getMessage());
        }
        self::assertSame('local', $settings->active());

        // Reprise : seul le fichier manquant est copié, puis bascule
        $s3->failing = [];
        $summary = $this->runner($settings, $store)->run(new Job('storage', 'Migration'), $this->context());
        self::assertSame('1 fichier(s) copié(s), 2 déjà présent(s) ; stockage actif : S3', $summary);
        self::assertSame('s3', $settings->active());
        self::assertSame('s3', $store->backend()->name());
        self::assertSame('paquet b', $store->read($blobs[1]));
        self::assertCount(3, $s3->objects);
        // Fichiers d'origine conservés sur disque
        self::assertFileExists($store->localBackend()->path(BlobStore::key($blobs[0])));

        // Nouveau fichier : directement dans le bucket
        $new = $store->putString('paquet d')['sha256'];
        self::assertFalse($store->localBackend()->has(BlobStore::key($new)));

        // Retour au disque : le nouveau fichier redescend
        $summary = $this->runner($settings, $store)->run(new Job('storage', 'Retour'), $this->context());
        self::assertSame('1 fichier(s) copié(s), 3 déjà présent(s) ; stockage actif : disque', $summary);
        self::assertSame('local', $store->backend()->name());
        self::assertSame('paquet d', $store->read($new));
    }

    private function runner(StorageSettings $settings, BlobStore $store): StorageRunner
    {
        return new StorageRunner($settings, $store, new BlobMigration(), $this->dir.'/storage/tmp');
    }

    private function context(): JobContext
    {
        return new JobContext($this->em()->getConnection(), new NilUuid());
    }

    private function login(Role $role, string $name = 'compte'): void
    {
        $user = new User($name);
        $user->setRole($role);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret-test')); // gitleaks:allow
        $this->em()->persist($user);
        $this->em()->flush();
        $this->client->loginUser($user);
        Csrf::prime($this->client);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array<mixed>
     *
     * @phpstan-impure dernière réponse reçue : change à chaque requête
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getInternalResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
