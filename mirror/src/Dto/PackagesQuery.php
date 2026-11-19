<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Paquets servis : filtres et pagination. */
final readonly class PackagesQuery
{
    public const int MAX = 50;

    public function __construct(
        #[Assert\Choice(choices: ['', 'npm', 'pypi', 'composer', 'docker', 'deb', 'rpm', 'galaxy', 'terraform', 'vscode'])]
        public string $ecosystem = '',
        #[Assert\Length(max: 200)]
        public string $q = '',
        #[Assert\Positive]
        public int $page = 1,
    ) {
    }
}
