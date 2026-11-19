<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Action sensible sur son compte (double authentification) : le mot de passe est redemandé. */
final readonly class PasswordConfirmInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Saisissez votre mot de passe.')]
        public string $password = '',
    ) {
    }
}
