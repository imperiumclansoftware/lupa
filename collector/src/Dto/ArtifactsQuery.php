<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Catalogue des paquets collectés : filtres et pagination. */
final readonly class ArtifactsQuery
{
    public function __construct(
        #[Assert\Choice(choices: ['', 'npm', 'pypi', 'composer', 'docker', 'deb', 'rpm', 'galaxy', 'terraform', 'vscode'])]
        public string $ecosystem = '',
        #[Assert\Length(max: 200)]
        public string $q = '',
        public bool $pending = false,
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Range(min: 1, max: PageQuery::MAX)]
        public int $pageSize = PageQuery::MAX,
    ) {
    }

    public function page(): PageQuery
    {
        return new PageQuery($this->page, $this->pageSize);
    }
}
