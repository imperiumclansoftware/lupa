<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\Role;
use App\Tests\MirrorWebTestCase;
use App\Tests\Support\Csrf;
use OTPHP\TOTP;

/** Rôles (ADR 0010), double authentification (ADR 0009) et identifiants UUID v7 (ADR 0006). */
final class RolesAndTwoFactorTest extends MirrorWebTestCase
{
    private const string UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testReaderOnlyReads(): void
    {
        $this->login(Role::Reader);

        $this->client->request('GET', '/api/packages');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/account');
        self::assertSame('reader', $this->json()['role']);

        $this->client->jsonRequest('POST', '/api/inbox/import', ['source' => 'usb', 'name' => 'sas-x.tar']);
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('rôle', $this->json()['error']);
        $this->client->request('GET', '/api/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testOperatorImportsButCannotTrustOrManageKeys(): void
    {
        $this->login(Role::Operator);

        // Passe le contrôle d'accès : le bundle n'existe simplement pas
        $this->client->jsonRequest('POST', '/api/inbox/import', ['source' => 'usb', 'name' => 'sas-absent.tar']);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/keys', ['publicKey' => str_repeat('a', 64), 'label' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminManagesAccountsWithSafeguards(): void
    {
        $admin = $this->login();

        $this->client->jsonRequest('POST', '/api/users', ['username' => 'lea', 'role' => 'operator', 'password' => 'Pivoine-Granit-1987']); // gitleaks:allow (mot de passe de test)
        self::assertResponseStatusCodeSame(201);
        $lea = $this->json();
        self::assertMatchesRegularExpression(self::UUID_V7, $lea['id']);
        self::assertSame('operator', $lea['role']);
        self::assertArrayNotHasKey('password', $lea);

        $this->client->jsonRequest('POST', '/api/users', ['username' => 'lea', 'role' => 'reader', 'password' => 'Pivoine-Granit-1987']); // gitleaks:allow (mot de passe de test)
        self::assertResponseStatusCodeSame(409);
        $this->client->jsonRequest('POST', '/api/users', ['username' => 'bob', 'role' => 'reader', 'password' => 'court']);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('PUT', '/api/users/'.$lea['id'], ['role' => 'reader']);
        self::assertSame('reader', $this->json()['role']);

        // Garde-fous : ni son propre rôle, ni son propre compte, ni le dernier administrateur
        $this->client->jsonRequest('PUT', '/api/users/'.$admin->getId(), ['role' => 'reader']);
        self::assertResponseStatusCodeSame(409);
        $this->client->request('DELETE', '/api/users/'.$admin->getId());
        self::assertResponseStatusCodeSame(409);

        // Ancien identifiant entier : la route ne correspond plus
        $this->client->request('DELETE', '/api/users/1');
        self::assertResponseStatusCodeSame(404);

        // Stockage en BLOB (BinaryUuidType) : une valeur liée en texte ne serait plus retrouvée
        self::assertSame(['blob'], $this->em()->getConnection()->fetchFirstColumn('SELECT DISTINCT typeof(id) FROM "user"'));

        $this->client->request('DELETE', '/api/users/'.$lea['id']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(1, $this->countRows(User::class));
    }

    public function testTwoFactorLogin(): void
    {
        $this->createUser('chef', 'motdepasse-test');
        self::assertSame(['username' => 'chef'], $this->passwordLogin('chef', 'motdepasse-test'));

        // Activation : mot de passe redemandé, puis un code valide confirme l'enregistrement du secret
        $this->client->jsonRequest('POST', '/api/account/2fa/setup', ['password' => 'mauvais']);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/account/2fa/setup', ['password' => 'motdepasse-test']);
        $setup = $this->json();
        self::assertStringStartsWith('otpauth://totp/', $setup['uri']);
        self::assertGreaterThanOrEqual(21, $setup['qr']['size']);
        self::assertStringStartsWith('M', $setup['qr']['path']);
        $secret = str_replace(' ', '', (string) $setup['secret']);
        self::assertNotSame('', $secret);
        $totp = TOTP::createFromSecret($secret);

        $this->client->jsonRequest('POST', '/api/account/2fa/enable', ['code' => '000000' === $totp->now() ? '111111' : '000000']);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/account/2fa/enable', ['code' => $totp->now()]);
        self::assertResponseIsSuccessful();
        $codes = $this->json()['codes'];
        self::assertCount(10, $codes);
        self::assertTrue($this->json()['twoFactor']);
        $this->client->request('POST', '/api/logout');

        // Mot de passe correct : la session attend le code, l'API reste fermée
        self::assertSame(['twoFactor' => true], $this->passwordLogin('chef', 'motdepasse-test'));
        $this->client->request('GET', '/api/session');
        self::assertNull($this->json()['user']);
        self::assertTrue($this->json()['twoFactor']);
        $this->client->request('GET', '/api/packages');
        self::assertResponseStatusCodeSame(401);

        $this->client->jsonRequest('POST', '/api/2fa/check', ['code' => '000000' === $totp->now() ? '111111' : '000000']);
        self::assertResponseStatusCodeSame(401);
        self::assertTrue($this->json()['twoFactor']);
        $this->client->jsonRequest('POST', '/api/2fa/check', ['code' => $totp->now()]);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/packages');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/api/logout');

        // Code de secours : accepté une seule fois
        foreach ([200, 401] as $status) {
            $this->passwordLogin('chef', 'motdepasse-test');
            $this->client->jsonRequest('POST', '/api/2fa/check', ['code' => strtolower($codes[0])]);
            self::assertResponseStatusCodeSame($status);
            $this->client->request('POST', '/api/logout');
        }
    }

    public function testTooManyWrongCodesCloseTheSession(): void
    {
        $user = $this->createUser('chef', 'motdepasse-test');
        $user->enableTotp('JBSWY3DPEHPK3PXP', []);
        $this->em()->flush();

        $this->passwordLogin('chef', 'motdepasse-test');
        for ($i = 1; $i <= 5; ++$i) {
            $this->client->jsonRequest('POST', '/api/2fa/check', ['code' => '000000']);
            self::assertResponseStatusCodeSame(401);
        }
        self::assertFalse($this->json()['twoFactor']);
        $this->client->request('GET', '/api/session');
        self::assertFalse($this->json()['twoFactor']);
        self::assertNull($this->json()['user']);
    }

    /** @return array<mixed> réponse de /api/login */
    private function passwordLogin(string $username, string $password): array
    {
        Csrf::prime($this->client);
        $this->client->jsonRequest('POST', '/api/login', ['username' => $username, 'password' => $password]);
        self::assertResponseIsSuccessful();
        $response = $this->json();
        // Nouvelle session : nouveau jeton CSRF
        Csrf::prime($this->client);

        return $response;
    }
}
