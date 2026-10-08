<?php

namespace App\Command;

use App\Setup\SetupToken;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:setup:code', 'Affiche le code d\'installation (tant qu\'aucun compte n\'existe)')]
final class SetupCodeCommand
{
    public function __construct(private readonly SetupToken $token)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (!$this->token->isRequired()) {
            $this->token->consume();
            $io->text('Installation terminée : un compte existe déjà.');

            return Command::SUCCESS;
        }
        $io->text(['', sprintf('  Code d\'installation : <info>%s</info>', $this->token->code()), '  À saisir dans l\'assistant d\'installation (première ouverture de l\'interface).', '']);

        return Command::SUCCESS;
    }
}
