<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Identity\CollectorIdentity;
use App\Setup\SetupToken;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Assistant d'installation : premier compte protégé par le code lu sur le serveur. */
final class SetupTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-setup-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o777, true);
        foreach (['SETUP_TOKEN_FILE' => $this->dir.'/setup.code', 'IDENTITY_DIR' => $this->dir.'/identity', 'STORAGE_DIR' => $this->dir.'/storage'] as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }
        $this->client = static::createClient();
        $em = $this->em();
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = OFF');
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testFirstAccountWithInstallationCode(): void
    {
        $this->client->request('GET', '/api/session');
        self::assertSame(['user' => null, 'setupRequired' => true], $this->json());

        $this->client->request('GET', '/api/setup');
        self::assertTrue($this->json()['required']);
        self::assertFileExists($this->dir.'/setup.code');
        $code = static::getContainer()->get(SetupToken::class)->code();
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);

        // Mauvais code : rien n'est créé
        $this->client->jsonRequest('POST', '/api/setup', ['code' => 'AAAA-BBBB-CCCC', 'username' => 'admin', 'password' => 'phrase-de-passe-longue']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em()->getRepository(User::class)->count([]));

        // Mot de passe trop court
        $this->client->jsonRequest('POST', '/api/setup', ['code' => $code, 'username' => 'admin', 'password' => 'court']);
        self::assertResponseStatusCodeSame(422);

        // Bon code (casse et tirets indifférents) : compte créé, collecteur nommé, session ouverte
        $this->client->jsonRequest('POST', '/api/setup', ['code' => strtolower(str_replace('-', '', $code)), 'username' => 'chef', 'password' => 'phrase-de-passe-longue', 'name' => 'Rémus bureau']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('chef', $this->json()['user']['username']);
        self::assertFileDoesNotExist($this->dir.'/setup.code');
        self::assertSame('Rémus bureau', static::getContainer()->get(CollectorIdentity::class)->name());

        $this->client->request('GET', '/api/overview');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/session');
        self::assertSame(['user' => ['username' => 'chef'], 'setupRequired' => false], $this->json());

        // Une fois installé, l'assistant est fermé
        $this->client->jsonRequest('POST', '/api/setup', ['code' => $code, 'username' => 'intrus', 'password' => 'phrase-de-passe-longue']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->em()->getRepository(User::class)->count([]));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array<mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
