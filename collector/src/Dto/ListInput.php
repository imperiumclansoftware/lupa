<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ListInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Donnez un nom à la liste.'), Assert\Length(max: 120)]
        public string $name = '',
        #[Assert\Choice(choices: ['npm', 'pypi', 'composer', 'docker'], message: 'Écosystème inconnu.')]
        public string $ecosystem = 'npm',
        #[Assert\Length(max: 200_000, maxMessage: 'Liste trop longue.')]
        public string $specs = '',
        /** @var array<string, mixed> */
        public array $options = [],
        #[Assert\Length(max: 255)]
        public ?string $lockfileName = null,
        #[Assert\Length(max: 10_000_000, maxMessage: 'Fichier de verrouillage trop volumineux (10 Mo maximum).')]
        public ?string $lockfileContent = null,
        /** true : retire le fichier de verrouillage existant */
        public bool $removeLockfile = false,
    ) {
    }
}
