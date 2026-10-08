<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class SettingsInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le nom du collecteur est obligatoire.'), Assert\Length(max: 60)]
        public string $name = '',
    ) {
    }
}
