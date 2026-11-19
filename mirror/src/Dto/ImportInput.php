<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Import d'un bundle de la clé USB ou envoyé par le navigateur. */
final class ImportInput
{
    public function __construct(
        #[Assert\Choice(choices: ['usb', 'upload'], message: 'Source inconnue.')]
        public string $source = '',
        #[Assert\NotBlank, Assert\Regex('/^[A-Za-z0-9._-]{1,200}$/')]
        public string $name = '',
        /** Approuve la clé du collecteur avant l'import, après comparaison de l'empreinte */
        public bool $trust = false,
        #[Assert\Length(max: 100)]
        public ?string $fingerprint = null,
        /** Envoi par le navigateur : supprimer les fichiers après l'import */
        public bool $deleteAfter = true,
    ) {
    }
}
