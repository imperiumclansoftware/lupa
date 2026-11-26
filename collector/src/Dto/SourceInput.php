<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SourceInput
{
    public function __construct(
        #[Assert\Choice(choices: ['npm', 'pypi', 'composer', 'docker', 'deb'], message: 'Écosystème inconnu.')]
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
        /** Debian, RPM : distribution de rattachement (debian-12, rocky-9…) */
        #[Assert\Length(max: 32)]
        public ?string $distribution = null,
        /** Debian : suite (bookworm, bookworm-pgdg…) */
        #[Assert\Length(max: 120)]
        public ?string $suite = null,
        /** Debian : composants séparés par des espaces (main, stable…) */
        #[Assert\Length(max: 500)]
        public ?string $components = null,
        /** Debian, RPM : clé publique OpenPGP du dépôt (ASCII armor) */
        #[Assert\Length(max: 100000)]
        public ?string $signingKey = null,
        /** Test d'un dépôt déjà enregistré : réutilise son secret si aucun n'est saisi */
        #[Assert\Uuid(versions: [Assert\Uuid::V7_MONOTONIC])]
        public ?string $id = null,
    ) {
    }
}
