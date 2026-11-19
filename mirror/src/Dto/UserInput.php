<?php

namespace App\Dto;

use App\Security\PasswordPolicy;
use App\Security\Role;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Création d'un compte par un administrateur (mot de passe provisoire, à changer par son titulaire). */
final class UserInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Choisissez un identifiant.'), Assert\Regex('/^[A-Za-z0-9._@-]{3,180}$/', message: 'Identifiant : 3 caractères minimum, lettres, chiffres et . _ @ - uniquement.')]
        public string $username = '',
        public Role $role = Role::Reader,
        /** Contraintes : PasswordPolicy */
        public string $password = '',
    ) {
    }

    #[Assert\Callback]
    public function validatePassword(ExecutionContextInterface $context): void
    {
        $context->getValidator()->inContext($context)->atPath('password')->validate($this->password, PasswordPolicy::constraints());
    }
}
