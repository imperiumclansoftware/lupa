<?php

namespace Sas\Bundle;

/**
 * Utilisateur du conteneur et Docker rootless, pour expliquer un dossier monté inaccessible.
 *
 * En Docker rootless, l'UID 0 du conteneur correspond à l'utilisateur de l'hôte (/proc/self/uid_map
 * commence par « 0 <uid hôte non nul> ») : c'est sous cet UID que les clés USB et dossiers de l'hôte
 * sont accessibles. Tout autre UID correspond à un sous-UID sans droit sur eux.
 */
final class ContainerUser
{
    public static function uid(): ?int
    {
        return \function_exists('posix_geteuid') ? posix_geteuid() : null;
    }

    /** Docker rootless détecté (root du conteneur traduit vers un utilisateur non root de l'hôte). */
    public static function isRootless(string $uidMap = '/proc/self/uid_map'): bool
    {
        $map = @file_get_contents($uidMap);
        if (false === $map) {
            return false;
        }
        foreach (preg_split('/\R/', trim($map)) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if ('0' === ($fields[0] ?? null)) {
                return '0' !== ($fields[1] ?? '0');
            }
        }

        return false;
    }

    /**
     * Réglage à faire pour accéder à un dossier de l'hôte, ou null si l'utilisateur n'est pas en cause.
     */
    public static function hint(string $path, string $app): ?string
    {
        $uid = self::uid();
        if (null === $uid) {
            return null;
        }
        if (0 !== $uid && self::isRootless()) {
            return sprintf('Docker rootless : %s tourne avec l\'UID %d, qui n\'a aucun droit sur les dossiers de votre utilisateur. '
                .'Réglez SAS_UID=0 dans le fichier .env (./configure.sh le détecte), puis « docker compose up -d ».', $app, $uid);
        }
        $owner = @fileowner($path);
        if (false !== $owner && $owner !== $uid && $owner !== 65534) {
            return sprintf('%s appartient à l\'UID %d alors que %s tourne avec l\'UID %d : réglez SAS_UID=%d dans le fichier .env, puis « docker compose up -d ».',
                $path, $owner, $app, $uid, $owner);
        }

        return null;
    }
}
