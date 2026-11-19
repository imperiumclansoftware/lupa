<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class SetupInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Saisissez le code d\'installation.')]
        public string $code = '',
        #[Assert\NotBlank(message: 'Choisissez un identifiant.'), Assert\Regex('/^[A-Za-z0-9._@-]{3,180}$/', message: 'Identifiant : 3 caractères minimum, lettres, chiffres et . _ @ - uniquement.')]
        public string $username = '',
        /** Contraintes : PasswordPolicy (validées par SetupInput::validatePassword) */
        public string $password = '',
        /** Clé publique d'un collecteur à approuver d'emblée (facultatif) */
        #[Assert\Length(max: 100)]
        public ?string $publicKey = null,
        #[Assert\Length(max: 120)]
        public ?string $keyLabel = null,
    ) {
    }

    #[Assert\Callback]
    public function validatePassword(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        $context->getValidator()->inContext($context)->atPath('password')->validate($this->password, \App\Security\PasswordPolicy::constraints());
    }
}
