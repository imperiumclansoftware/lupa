<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Recherche dans les registres (formulaire de liste) : paquet, ou versions d'un paquet. */
final class SearchQuery
{
    public function __construct(
        #[Assert\NotBlank, Assert\Choice(choices: ['npm', 'pypi', 'composer', 'docker', 'deb', 'rpm', 'galaxy', 'terraform'], message: 'Écosystème inconnu.')]
        public string $ecosystem = '',
        #[Assert\Length(max: 200)]
        public string $q = '',
        #[Assert\Length(max: 255)]
        public string $name = '',
        #[Assert\Length(max: 40)]
        public ?string $distribution = null,
    ) {
    }
}
