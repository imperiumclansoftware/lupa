<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\Role;
use App\Tests\Support\Csrf;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Droits par rôle sur Rémus (ADR 0010) : chaque action d'écriture est essayée avec les trois rôles.
 * Identifiants exposés en UUID v7 (ADR 0006).
 */
final class RolesTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = OFF');
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
    }

    public function testEachRoleGetsItsOwnActions(): void
    {
        // Liste créée par un administrateur, puis essayée par chaque rôle
        $this->loginAs(Role::Admin);
        $this->client->jsonRequest('POST', '/api/lists', ['name' => 'Projet', 'ecosystem' => 'npm', 'specs' => 'lodash@4.17.21']);
        self::assertResponseStatusCodeSame(201);
        $list = $this->json()['id'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $list);

        // Contenu d'une liste : jointure par UUID (BLOB)
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $artifact = new \App\Entity\Artifact(\Sas\Bundle\Ecosystem::Npm, 'lodash', '4.17.21', null);
        $em->persist($artifact);
        $em->find(\App\Entity\PackageList::class, $list)?->getArtifacts()->add($artifact);
        $em->flush();
        $this->client->request('GET', '/api/lists/'.$list.'/artifacts');
        self::assertSame(1, $this->json()['total']);

        $cases = [
            // [méthode, chemin, corps, lecteur, opérateur, administrateur]
            ['GET', '/api/lists', null, 200, 200, 200],
            ['GET', '/api/lists/'.$list, null, 200, 200, 200],
            ['PUT', '/api/lists/'.$list, ['name' => 'Projet', 'ecosystem' => 'npm', 'specs' => 'lodash@4.17.21'], 403, 200, 200],
            ['POST', '/api/blacklist', ['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.20'], 403, 403, 201],
            ['PUT', '/api/settings', ['name' => 'Rémus test'], 403, 403, 200],
            ['GET', '/api/users', null, 403, 403, 200],
            ['GET', '/api/account', null, 200, 200, 200],
        ];
        foreach ([[Role::Reader, 3], [Role::Operator, 4], [Role::Admin, 5]] as [$role, $column]) {
            $this->loginAs($role);
            foreach ($cases as $case) {
                $this->client->request($case[0], $case[1], server: ['CONTENT_TYPE' => 'application/json'], content: null === $case[2] ? null : json_encode($case[2], \JSON_THROW_ON_ERROR));
                self::assertResponseStatusCodeSame($case[$column], \sprintf('%s %s %s', $role->value, $case[0], $case[1]));
            }
        }

        // Suppression d'une liste : administrateurs seulement
        $this->loginAs(Role::Operator);
        $this->client->request('DELETE', '/api/lists/'.$list);
        self::assertResponseStatusCodeSame(403);
        $this->loginAs(Role::Admin);
        $this->client->request('DELETE', '/api/lists/1');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', '/api/lists/'.$list);
        self::assertResponseIsSuccessful();
    }

    private function loginAs(Role $role): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['username' => $role->value]);
        if (null === $user) {
            $user = new User($role->value, $role);
            $user->setPassword('!');
            $em->persist($user);
            $em->flush();
        }
        $this->client->loginUser($user);
        Csrf::prime($this->client);
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
