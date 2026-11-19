<?php

namespace App\Security;

use App\Entity\User;

/** Compte tel que l'API le montre : jamais le mot de passe, le secret TOTP ni les codes de secours. */
final class UserView
{
    /** @return array{id: string, username: string, role: string, roleLabel: string, twoFactor: bool, backupCodes: int} */
    public static function of(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'username' => $user->getUsername(),
            'role' => $user->getRole()->value,
            'roleLabel' => $user->getRole()->label(),
            'twoFactor' => $user->isTotpAuthenticationEnabled(),
            'backupCodes' => $user->getBackupCodeCount(),
        ];
    }
}
