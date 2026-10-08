<?php

namespace App\Tests\Functional;

use App\Entity\TrustedKey;
use App\Entity\User;
use App\Setup\SetupToken;
use App\Tests\MirrorWebTestCase;
use Sas\Bundle\Signer;

/** Assistant d'installation de Romulus : premier compte et, en option, collecteur approuvé. */
final class SetupTest extends MirrorWebTestCase
{
    protected function setUp(): void
    {
        $file = sys_get_temp_dir().'/sas-mirror-setup-'.bin2hex(random_bytes(4)).'.code';
        $_ENV['SETUP_TOKEN_FILE'] = $_SERVER['SETUP_TOKEN_FILE'] = $file;
        putenv('SETUP_TOKEN_FILE='.$file);
        parent::setUp();
    }

    public function testFirstAccountAndTrustedCollector(): void
    {
        $this->client->request('GET', '/api/session');
        self::assertTrue($this->json()['setupRequired']);
        $code = static::getContainer()->get(SetupToken::class)->code();
        $keys = Signer::generateKeyPair();

        $this->client->jsonRequest('POST', '/api/setup', ['code' => $code, 'username' => 'admin', 'password' => 'phrase-de-passe-longue', 'publicKey' => 'pas-une-cle']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countRows(User::class));

        $this->client->jsonRequest('POST', '/api/setup', ['code' => $code, 'username' => 'admin', 'password' => 'phrase-de-passe-longue', 'publicKey' => $keys['publicKey'], 'keyLabel' => 'Rémus bureau']);
        self::assertResponseStatusCodeSame(201);
        $key = $this->em()->getRepository(TrustedKey::class)->findOneBy([]);
        self::assertSame('Rémus bureau', $key?->getLabel());

        $this->client->request('GET', '/api/keys');
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', '/api/setup', ['code' => $code, 'username' => 'intrus', 'password' => 'phrase-de-passe-longue']);
        self::assertResponseStatusCodeSame(409);
    }
}
