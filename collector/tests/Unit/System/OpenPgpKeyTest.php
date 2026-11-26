<?php

namespace App\Tests\Unit\System;

use PHPUnit\Framework\TestCase;
use Sas\Bundle\OpenPgpKey;

/** Empreinte v4 des clés publiques (comparée à celle affichée par gpg --show-keys). */
final class OpenPgpKeyTest extends TestCase
{
    public function testFingerprints(): void
    {
        $keys = \dirname(__DIR__, 3).'/resources/keys';
        self::assertSame('F6ECB3762474EDA9D21B7022871920D1991BC93C', OpenPgpKey::fingerprint((string) file_get_contents($keys.'/ubuntu-archive-2018.asc')));
        self::assertSame('21CB256AE16FC54C6E652949702D426D350D275D', OpenPgpKey::fingerprint((string) file_get_contents($keys.'/rocky-9.asc')));
        self::assertSame('F9BD3017B38B644C8F9EFF2D29A000A37390BBF1', OpenPgpKey::fingerprint((string) file_get_contents(\dirname(__DIR__, 2).'/fixtures/apt/key.asc')));
    }

    public function testInvalidKeys(): void
    {
        self::assertNull(OpenPgpKey::fingerprint(''));
        self::assertNull(OpenPgpKey::fingerprint('pas une clé'));
        self::assertNull(OpenPgpKey::fingerprint("-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nAAAA\n-----END PGP PUBLIC KEY BLOCK-----"));
    }
}
