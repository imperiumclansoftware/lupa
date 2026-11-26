<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Réglages › À propos : version de Rémus, réservée aux comptes connectés. */
final class AboutTest extends WebTestCase
{
    public function testVersion(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = OFF');
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        $client->request('GET', '/api/about');
        self::assertResponseStatusCodeSame(401);

        $user = new User('lecteur');
        $user->setRole(Role::Reader);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret-test')); // gitleaks:allow
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
        $client->request('GET', '/api/about');
        self::assertResponseIsSuccessful();
        $about = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['Rémus', 'dev', 'MIT'], [$about['application'], $about['version'], $about['license']]);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $about['php']);
    }
}
