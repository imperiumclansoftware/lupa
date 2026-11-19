<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class TotpCodeInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Saisissez le code affiché par l\'application.'), Assert\Regex('/^\s*\d{3}\s?\d{3}\s*$/', message: 'Le code compte six chiffres.')]
        public string $code = '',
    ) {
    }
}
