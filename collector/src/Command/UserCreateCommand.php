<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\PasswordPolicy;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand('app:user:create', 'Crée un compte (ou change son mot de passe, et son rôle avec --role)')]
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

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Identifiant')] string $username,
        #[Option('Rôle : admin, operator ou reader (défaut : admin pour un nouveau compte, inchangé sinon)')] ?string $role = null,
    ): int {
        $chosen = null === $role ? null : Role::tryFrom($role);
        if (null !== $role && null === $chosen) {
            $io->error('Rôle inconnu : admin, operator ou reader.');

            return Command::INVALID;
        }

        // Même politique que l'assistant d'installation (ADR 0003, 0004)
        $password = $io->askHidden('Mot de passe (12 caractères minimum, difficile à deviner)', function (?string $value): string {
            $violations = $this->validator->validate((string) $value, PasswordPolicy::constraints());
            if (\count($violations) > 0) {
                throw new \RuntimeException((string) $violations->get(0)->getMessage());
            }

            return (string) $value;
        });

        $existing = $this->users->findOneBy(['username' => $username]);
        $user = $existing ?? new User($username, $chosen ?? Role::Admin);
        if (null !== $chosen) {
            $user->setRole($chosen);
        }
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();
        $this->audit->info($existing ? 'Mot de passe changé en console' : 'Compte créé en console', ['user' => $username, 'role' => $user->getRole()->value]);

        $io->success(sprintf('Compte « %s » enregistré (%s).', $username, $user->getRole()->label()));

        return Command::SUCCESS;
    }
}
