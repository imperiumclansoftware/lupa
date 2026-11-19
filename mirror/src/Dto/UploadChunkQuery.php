<?php

namespace App\Dto;

/** Envoi d'un morceau : position attendue dans le fichier (absente = -1, refusée avec la position reçue). */
final readonly class UploadChunkQuery
{
    public function __construct(
        public int $offset = -1,
    ) {
    }
}
