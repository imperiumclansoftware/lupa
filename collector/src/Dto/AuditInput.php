<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Nouvelle analyse de sécurité : une liste, ou tout le collecteur. */
final class AuditInput
{
    public function __construct(
        #[Assert\Positive]
        public ?int $listId = null,
    ) {
    }
}
