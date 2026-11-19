<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;

/** Téléphone perdu sans code de secours : le compte se reconnecte avec son seul mot de passe (ADR 0009). */
#[AsCommand('app:user:2fa-reset', 'Désactive la double authentification d\'un compte')]
final class UserTwoFactorResetCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        #[Target('audit')]
        private readonly LoggerInterface $audit,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Identifiant')] string $username): int
    {
        $user = $this->users->findOneBy(['username' => $username]);
        if (null === $user) {
            $io->error(\sprintf('Compte « %s » introuvable.', $username));

            return Command::FAILURE;
        }
        $user->disableTotp();
        $this->em->flush();
        $this->audit->notice('Double authentification réinitialisée en console', ['user' => $username]);
        $io->success(\sprintf('Double authentification désactivée pour « %s » : il pourra la réactiver depuis « Mon compte ».', $username));

        return Command::SUCCESS;
    }
}
