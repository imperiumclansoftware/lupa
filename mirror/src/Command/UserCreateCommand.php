<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Security\PasswordPolicy;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand('app:user:create', 'Crée un compte (ou change son mot de passe)')]
final class UserCreateCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
        #[\Symfony\Component\DependencyInjection\Attribute\Target('audit')]
        private readonly \Psr\Log\LoggerInterface $audit,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Identifiant')] string $username): int
    {
        // Même politique que l'assistant d'installation (ADR 0003, 0004)
        $password = $io->askHidden('Mot de passe (12 caractères minimum, difficile à deviner)', function (?string $value): string {
            $violations = $this->validator->validate((string) $value, PasswordPolicy::constraints());
            if (\count($violations) > 0) {
                throw new \RuntimeException((string) $violations->get(0)->getMessage());
            }

            return (string) $value;
        });

        $existing = $this->users->findOneBy(['username' => $username]);
        $user = $existing ?? new User($username);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();
        $this->audit->info($existing ? 'Mot de passe changé en console' : 'Compte créé en console', ['user' => $username]);

        $io->success(sprintf('Compte « %s » enregistré.', $username));

        return Command::SUCCESS;
    }
}
