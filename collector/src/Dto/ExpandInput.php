<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Aperçu d'un motif de noms (« * », « ? ») avant de l'ajouter à une liste. */
final readonly class ExpandInput
{
    public function __construct(
        #[Assert\Choice(choices: ['npm', 'pypi', 'composer', 'docker', 'deb', 'rpm', 'galaxy', 'terraform'], message: 'Écosystème inconnu.')]
        public string $ecosystem = 'npm',
        #[Assert\NotBlank, Assert\Length(max: 300)]
        public string $line = '',
        #[Assert\Length(max: 40)]
        public ?string $distribution = null,
    ) {
    }
}
