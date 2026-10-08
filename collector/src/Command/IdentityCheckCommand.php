<?php

namespace App\Command;

use App\Identity\CollectorIdentity;
use App\Identity\IdentityException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vérifie l'identité de Rémus au démarrage (et la crée la première fois).
 * Une clé de signature existante peut être fournie par REMUS_SIGNING_KEY ou REMUS_SIGNING_KEY_FILE
 * (réinstallation sur une nouvelle machine) ; elle ne remplace jamais une clé déjà installée.
 */
#[AsCommand('app:identity:check', 'Vérifie la clé de signature du collecteur (la crée au premier démarrage)')]
final class IdentityCheckCommand
{
    public function __construct(private readonly CollectorIdentity $identity)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $provided = self::provided();
            foreach ($this->identity->ensure($provided) as $notice) {
                $io->text($notice);
            }
        } catch (IdentityException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->text(sprintf('Collecteur « %s », empreinte de la clé : %s', $this->identity->name(), $this->identity->fingerprint()));

        return Command::SUCCESS;
    }

    private static function provided(): ?string
    {
        $value = getenv('REMUS_SIGNING_KEY');
        if (\is_string($value) && '' !== trim($value)) {
            return trim($value);
        }
        $file = getenv('REMUS_SIGNING_KEY_FILE');
        if (\is_string($file) && '' !== $file) {
            if (!is_readable($file)) {
                throw new IdentityException(sprintf('REMUS_SIGNING_KEY_FILE : fichier %s illisible.', $file));
            }

            return trim((string) file_get_contents($file));
        }

        return null;
    }
}
