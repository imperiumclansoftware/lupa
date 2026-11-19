<?php

namespace App\Dto;

use App\Security\PasswordPolicy;
use App\Security\Role;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Modification d'un compte par un administrateur : rôle et/ou nouveau mot de passe provisoire. */
final class UserUpdateInput
{
    public function __construct(
        public ?Role $role = null,
        /** Vide = inchangé ; sinon contraintes PasswordPolicy */
        public ?string $password = null,
    ) {
    }

    #[Assert\Callback]
    public function validatePassword(ExecutionContextInterface $context): void
    {
        if (null !== $this->password && '' !== $this->password) {
            $context->getValidator()->inContext($context)->atPath('password')->validate($this->password, PasswordPolicy::constraints());
        }
    }
}
