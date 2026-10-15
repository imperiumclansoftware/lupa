<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sas\Bundle\ContainerUser;

final class ContainerUserTest extends TestCase
{
    public function testDetectsDockerRootlessFromUidMap(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'uidmap');
        // Docker rootless : root du conteneur = utilisateur 1000 de l'hôte, puis plage de sous-UID
        file_put_contents($file, "         0       1000          1\n         1     100000      65536\n");
        self::assertTrue(ContainerUser::isRootless($file));
        // Docker classique : identité
        file_put_contents($file, "         0          0 4294967295\n");
        self::assertFalse(ContainerUser::isRootless($file));
        unlink($file);
        self::assertFalse(ContainerUser::isRootless('/inexistant'));
    }
}
