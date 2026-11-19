<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Clé publique d'un collecteur, ajoutée à la main (copiée depuis ses réglages). */
final readonly class KeyInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Collez la clé publique du collecteur.'), Assert\Length(max: 200)]
        public string $publicKey = '',
        #[Assert\Length(max: 120)]
        public string $label = '',
    ) {
    }
}
