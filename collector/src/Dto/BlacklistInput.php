<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class BlacklistInput
{
    public function __construct(
        #[Assert\Choice(choices: ['npm', 'pypi', 'composer', 'docker', 'deb', 'rpm', 'galaxy', 'terraform'], message: 'Écosystème inconnu.')]
        public string $ecosystem = '',
        #[Assert\NotBlank(message: 'Le nom du paquet est obligatoire.'), Assert\Length(max: 255)]
        public string $name = '',
        #[Assert\NotBlank(message: 'La version est obligatoire : la liste noire vise des versions précises.'), Assert\Length(max: 128)]
        public string $version = '',
        #[Assert\Length(max: 500)]
        public string $reason = '',
    ) {
    }
}
