<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class SourceInput
{
    public function __construct(
        #[Assert\Choice(choices: ['npm', 'pypi', 'composer', 'docker'], message: 'Écosystème inconnu.')]
        public string $ecosystem = '',
        #[Assert\NotBlank(message: 'Donnez un nom au dépôt.'), Assert\Length(max: 120)]
        public string $label = '',
        #[Assert\NotBlank(message: 'L\'adresse du dépôt est obligatoire.'), Assert\Length(max: 500)]
        public string $url = '',
        #[Assert\Length(max: 120)]
        public ?string $scope = null,
        #[Assert\Choice(choices: ['none', 'basic', 'token'], message: 'Authentification inconnue.')]
        public string $authType = 'none',
        #[Assert\Length(max: 255)]
        public ?string $username = null,
        /** Vide ou absent à la modification = secret inchangé */
        #[Assert\Length(max: 1000)]
        public ?string $secret = null,
        public bool $replacesDefault = false,
        public bool $enabled = true,
    ) {
    }
}
