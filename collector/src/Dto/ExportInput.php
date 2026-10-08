<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ExportInput
{
    public function __construct(
        public bool $full = false,
        /** Taille maximale d'un fichier en Mio (4095 pour une clé FAT32), null = un seul fichier */
        #[Assert\Range(min: 64, max: 1_048_576, notInRangeMessage: 'Taille de découpage entre 64 Mio et 1 Tio.')]
        public ?int $maxPartMiB = null,
    ) {
    }
}
