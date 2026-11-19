<?php

namespace App\Dto;

use App\Security\PasswordPolicy;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Changement de son propre mot de passe : l'actuel est redemandé. */
final class PasswordChangeInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Saisissez votre mot de passe actuel.')]
        public string $current = '',
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
