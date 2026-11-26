<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\Role;
use App\Tests\Support\Csrf;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Visites guidées vues, retenues sur le compte (même pour un lecteur). */
final class ToursTest extends WebTestCase
{
    public function testSeenTours(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = OFF');
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $user = new User('lecteur');
        $user->setRole(Role::Reader);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret-test')); // gitleaks:allow
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
        Csrf::prime($client);

        $client->jsonRequest('POST', '/api/account/tours', ['tour' => 'remus-accueil']);
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('POST', '/api/account/tours', ['tour' => 'remus-accueil']);
        $client->jsonRequest('POST', '/api/account/tours', ['tour' => '<script>']);
        self::assertResponseStatusCodeSame(422);

        $client->request('GET', '/api/session');
        self::assertSame(['remus-accueil'], json_decode((string) $client->getResponse()->getContent(), true)['user']['seenTours']);

        $client->request('DELETE', '/api/account/tours');
        self::assertResponseStatusCodeSame(204);
        $client->request('GET', '/api/session');
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true)['user']['seenTours']);
    }
}
