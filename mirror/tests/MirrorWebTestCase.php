<?php

namespace App\Tests;

use App\Entity\Job;
use App\Entity\TrustedKey;
use App\Entity\User;
use App\Security\Role;
use App\Tests\Support\Csrf;
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
        // Limiteur des connexions conservé sur disque entre deux exécutions : chaque test repart de zéro
        static::getContainer()->get('cache.rate_limiter')->clear();
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
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(string $username = 'admin', string $password = 'secret-test', Role $role = Role::Admin): User
    {
        $user = new User($username, $role);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $em = $this->em();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    protected function login(Role $role = Role::Admin): User
    {
        $user = $this->createUser($role->value, 'secret-test', $role);
        $this->client->loginUser($user);
        Csrf::prime($this->client);

        return $user;
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

    /**
     * @return array<mixed>
     *
     * @phpstan-impure dernière réponse reçue : change à chaque requête
     */
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
