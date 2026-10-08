<?php

namespace App\Tests\Unit\Identity;

use App\Identity\CollectorIdentity;
use App\Identity\IdentityException;
use PHPUnit\Framework\TestCase;
use Sas\Bundle\Signer;

/** La clé de signature n'est jamais remplacée en silence : les miroirs lui font confiance. */
final class CollectorIdentityTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-identity-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testFirstStartCreatesAKeyThenKeepsIt(): void
    {
        $identity = new CollectorIdentity($this->dir);
        self::assertSame(['Nouvelle clé de signature créée.', 'Identité du collecteur créée.'], $identity->ensure());
        $secret = $identity->secretKey();
        self::assertSame(Signer::publicKeyFromSecret($secret), $identity->publicKey());
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->dir.'/secret.key')), -4));

        // Redémarrage (mise à jour de l'image) : rien ne change
        $again = new CollectorIdentity($this->dir);
        self::assertSame([], $again->ensure());
        self::assertSame($secret, $again->secretKey());
        self::assertSame($identity->id(), $again->id());
    }

    public function testIdentityJsonIsRebuiltOrCorrectedFromTheSecretKey(): void
    {
        $identity = new CollectorIdentity($this->dir);
        $identity->ensure();
        $public = $identity->publicKey();

        unlink($this->dir.'/identity.json');
        self::assertSame(['Identité du collecteur créée.'], (new CollectorIdentity($this->dir))->ensure());
        self::assertSame($public, (new CollectorIdentity($this->dir))->publicKey());

        $data = json_decode((string) file_get_contents($this->dir.'/identity.json'), true);
        $data['publicKey'] = Signer::generateKeyPair()['publicKey'];
        file_put_contents($this->dir.'/identity.json', json_encode($data));
        self::assertStringContainsString('corrigée', (new CollectorIdentity($this->dir))->ensure()[0]);
        self::assertSame($public, (new CollectorIdentity($this->dir))->publicKey());
    }

    public function testLostSecretKeyStopsInsteadOfGeneratingANewOne(): void
    {
        (new CollectorIdentity($this->dir))->ensure();
        unlink($this->dir.'/secret.key');

        $this->expectException(IdentityException::class);
        $this->expectExceptionMessage('Clé secrète absente');
        (new CollectorIdentity($this->dir))->ensure();
    }

    public function testCorruptedSecretKeyIsNeverReplaced(): void
    {
        (new CollectorIdentity($this->dir))->ensure();
        file_put_contents($this->dir.'/secret.key', 'abîmée');

        try {
            (new CollectorIdentity($this->dir))->ensure();
            self::fail('Une clé corrompue doit bloquer le démarrage.');
        } catch (IdentityException $e) {
            self::assertStringContainsString('corrompue', $e->getMessage());
        }
        self::assertSame('abîmée', file_get_contents($this->dir.'/secret.key'));
    }

    public function testProvidedKeyRestoresALostKeyOrInitialisesANewInstall(): void
    {
        $keys = Signer::generateKeyPair();

        // Nouvelle machine : la clé fournie est importée
        $identity = new CollectorIdentity($this->dir);
        self::assertContains('Clé de signature importée depuis REMUS_SIGNING_KEY.', $identity->ensure($keys['secretKey']));
        self::assertSame($keys['publicKey'], $identity->publicKey());

        // Même clé fournie à chaque démarrage : aucun changement
        self::assertSame([], (new CollectorIdentity($this->dir))->ensure($keys['secretKey']));

        // secret.key perdu mais la bonne clé est fournie : restaurée
        unlink($this->dir.'/secret.key');
        (new CollectorIdentity($this->dir))->ensure($keys['secretKey']);
        self::assertSame($keys['secretKey'], trim((string) file_get_contents($this->dir.'/secret.key')));
    }

    public function testProvidedKeyNeverOverridesTheInstalledOne(): void
    {
        (new CollectorIdentity($this->dir))->ensure();
        $installed = file_get_contents($this->dir.'/secret.key');

        try {
            (new CollectorIdentity($this->dir))->ensure(Signer::generateKeyPair()['secretKey']);
            self::fail('Une clé différente ne doit pas remplacer la clé installée.');
        } catch (IdentityException $e) {
            self::assertStringContainsString('diffère', $e->getMessage());
        }
        self::assertSame($installed, file_get_contents($this->dir.'/secret.key'));

        $this->expectExceptionMessage('invalide');
        (new CollectorIdentity($this->dir))->ensure('pas-une-cle');
    }
}
