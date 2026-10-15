<?php

namespace App\Export;

use Sas\Bundle\ContainerUser;

/**
 * Vérifie que le dossier d'export (clé USB ou dossier monté) est utilisable, et explique sinon pourquoi.
 * Cas fréquent en Docker rootless : le dossier appartient à l'UID 0 du conteneur (votre utilisateur sur
 * l'hôte) alors que l'application tourne avec un autre UID.
 */
final class ExportDirectory
{
    /** @return string|null explication du problème, null si le dossier est utilisable */
    public static function problem(string $dir): ?string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o770, true)) {
            return sprintf('Dossier d\'export %s absent ou inaccessible : clé USB non montée, ou dossier non monté dans le conteneur ?', $dir);
        }
        $probe = $dir.'/.sas-write-test-'.bin2hex(random_bytes(4));
        if (false !== @file_put_contents($probe, '')) {
            @unlink($probe);

            return null;
        }

        if (null !== $hint = ContainerUser::hint($dir, 'Rémus')) {
            return sprintf('Impossible d\'écrire dans %s. %s', $dir, $hint);
        }

        return sprintf('Impossible d\'écrire dans %s : vérifiez ses droits sur l\'hôte (clé USB en lecture seule ?).', $dir);
    }
}
