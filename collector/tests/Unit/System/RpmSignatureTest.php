<?php

namespace App\Tests\Unit\System;

use App\System\RpmSignature;
use App\System\Signatures;
use PHPUnit\Framework\TestCase;

/** Signature des paquets RPM (paquets réels : EPEL 9, format récent ; CentOS 7, ancien format). */
final class RpmSignatureTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../fixtures/rpm';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-rpmsig-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testSignedPackagesAreAccepted(): void
    {
        $this->verifier()->verify(self::FIXTURES.'/epel-release-9-11.el9.noarch.rpm', self::key('epel-9'), 'epel-release');
        $this->verifier()->verify(self::FIXTURES.'/basesystem-10.0-7.el7.centos.noarch.rpm', (string) file_get_contents(\dirname(__DIR__, 3).'/resources/keys/centos-7.asc'), 'basesystem');
        $this->addToAssertionCount(2);
    }

    public function testWrongKeyIsRefused(): void
    {
        $this->expectExceptionMessage('Signature invalide pour epel-release');
        $this->verifier()->verify(self::FIXTURES.'/epel-release-9-11.el9.noarch.rpm', (string) file_get_contents(\dirname(__DIR__, 2).'/fixtures/apt/key.asc'), 'epel-release');
    }

    public function testModifiedPayloadIsRefused(): void
    {
        $this->expectExceptionMessage('contenu modifié');
        $this->verifier()->verify($this->tampered('epel-release-9-11.el9.noarch.rpm', -100), self::key('epel-9'), 'epel-release');
    }

    public function testModifiedPayloadOfOldPackageIsRefused(): void
    {
        $this->expectExceptionMessage('Signature invalide pour basesystem');
        $this->verifier()->verify($this->tampered('basesystem-10.0-7.el7.centos.noarch.rpm', -100), (string) file_get_contents(\dirname(__DIR__, 3).'/resources/keys/centos-7.asc'), 'basesystem');
    }

    public function testNotAnRpm(): void
    {
        file_put_contents($this->dir.'/x.rpm', str_repeat('x', 200));
        $this->expectExceptionMessage('ce n\'est pas un paquet RPM');
        $this->verifier()->verify($this->dir.'/x.rpm', self::key('epel-9'), 'x');
    }

    private function verifier(): RpmSignature
    {
        return new RpmSignature(new Signatures($this->dir));
    }

    /** Copie du paquet avec un octet modifié à la position donnée (négative : depuis la fin) */
    private function tampered(string $name, int $position): string
    {
        $data = (string) file_get_contents(self::FIXTURES.'/'.$name);
        $position = $position < 0 ? \strlen($data) + $position : $position;
        $data[$position] = \chr(\ord($data[$position]) ^ 0xFF);
        file_put_contents($this->dir.'/'.$name, $data);

        return $this->dir.'/'.$name;
    }

    private static function key(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.'/'.$name.'.asc');
    }
}
