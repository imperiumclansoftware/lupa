<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Début (ou reprise) de l'envoi d'un bundle depuis le navigateur. */
final readonly class UploadInput
{
    public function __construct(
        #[Assert\Regex('/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.tar(\.\d{3})?$/', message: 'Ce fichier n\'est pas un bundle Sas (nom attendu : sas-….tar ou sas-….tar.001).')]
        public string $filename = '',
        #[Assert\Positive(message: 'Taille invalide.')]
        public int $size = 0,
    ) {
    }
}
