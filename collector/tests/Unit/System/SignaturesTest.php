<?php

namespace App\Tests\Unit\System;

use App\System\Signatures;
use PHPUnit\Framework\TestCase;

/** Vérification des index signés avec les clés livrées et gpgv (fichiers réels d'AlmaLinux 8). */
final class SignaturesTest extends TestCase
{
    private const string KEYS = __DIR__.'/../../../resources/keys';
    private const string FIXTURES = __DIR__.'/../../fixtures/system';

    protected function setUp(): void
    {
        if (null === (new \Symfony\Component\Process\ExecutableFinder())->find('gpgv')) {
            self::markTestSkipped('gpgv absent.');
        }
    }

    public function testArmoredFileWithSeveralKeys(): void
    {
        // La clé AlmaLinux 8 contient deux blocs : les deux doivent être décodés
        $binary = Signatures::dearmor((string) file_get_contents(self::KEYS.'/almalinux-8.asc'));
        self::assertGreaterThan(2000, \strlen($binary));
        self::assertSame(2, substr_count((string) file_get_contents(self::KEYS.'/almalinux-8.asc'), 'BEGIN PGP PUBLIC KEY BLOCK'));
    }

    public function testValidAndTamperedIndex(): void
    {
        $signatures = new Signatures(self::KEYS);
        $xml = (string) file_get_contents(self::FIXTURES.'/alma8-repomd.xml');
        $asc = (string) file_get_contents(self::FIXTURES.'/alma8-repomd.xml.asc');
        $signatures->detached($xml, $asc, ['almalinux-8'], 'AlmaLinux 8');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Signature invalide');
        $signatures->detached(str_replace('<revision>', '<revision>9', $xml), $asc, ['almalinux-8'], 'AlmaLinux 8');
    }

    public function testWrongKeyIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Signatures(self::KEYS))->detached(
            (string) file_get_contents(self::FIXTURES.'/alma8-repomd.xml'),
            (string) file_get_contents(self::FIXTURES.'/alma8-repomd.xml.asc'),
            ['centos-7'],
            'AlmaLinux 8',
        );
    }
}
