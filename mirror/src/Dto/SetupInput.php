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
        #[Assert\Length(min: 12, max: 4096, minMessage: 'Mot de passe : 12 caractères minimum.')]
        public string $password = '',
        /** Clé publique d'un collecteur à approuver d'emblée (facultatif) */
        #[Assert\Length(max: 100)]
        public ?string $publicKey = null,
        #[Assert\Length(max: 120)]
        public ?string $keyLabel = null,
    ) {
    }
}
