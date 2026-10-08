<?php

namespace App\Tests;

use App\Entity\Job;
use App\Entity\TrustedKey;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base des tests fonctionnels du miroir : dossiers de stockage, d'entrée et d'envoi propres à chaque test,
 * schéma de base recréé à vide.
 */
abstract class MirrorWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-mirror-test-'.bin2hex(random_bytes(4));
        foreach (['storage', 'inbox', 'uploads', 'source'] as $sub) {
            mkdir($this->dir.'/'.$sub, 0o777, true);
        }
        self::setEnv('STORAGE_DIR', $this->dir.'/storage');
        self::setEnv('INBOX_DIR', $this->dir.'/inbox');
        self::setEnv('UPLOAD_DIR', $this->dir.'/uploads');
        self::setEnv('PUBLIC_URL', '');

        $this->client = static::createClient();
        $em = $this->em();
        // Clés étrangères suspendues : SQLite refuse sinon de supprimer une table encore référencée
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

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    protected function createUser(string $username = 'admin', string $password = 'secret-test'): User
    {
        $user = new User($username);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $em = $this->em();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    protected function login(): void
    {
        $this->client->loginUser($this->createUser());
    }

    protected function trust(string $publicKey): void
    {
        $em = $this->em();
        $em->persist(new TrustedKey($publicKey, 'Collecteur test'));
        $em->flush();
    }

    /** Lance l'import par l'API (transport synchrone en test : il s'exécute pendant la requête) et renvoie la tâche. */
    protected function import(string $name, string $source = 'usb'): Job
    {
        $this->client->jsonRequest('POST', '/api/inbox/import', ['source' => $source, 'name' => $name]);
        self::assertResponseStatusCodeSame(202);
        $id = $this->json()['id'];

        $em = $this->em();
        $em->clear();

        return $em->find(Job::class, $id);
    }

    /** @return array<mixed> */
    protected function json(): array
    {
        return json_decode((string) $this->client->getInternalResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** Corps de la dernière réponse, y compris pour les fichiers servis en BinaryFileResponse. */
    protected function body(): string
    {
        return (string) $this->client->getInternalResponse()->getContent();
    }

    protected function countRows(string $entity): int
    {
        return $this->em()->getRepository($entity)->count([]);
    }

    private static function setEnv(string $name, string $value): void
    {
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }
}
