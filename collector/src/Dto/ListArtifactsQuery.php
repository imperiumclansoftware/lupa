<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Contenu d'une liste : filtres et pagination côté serveur. */
final readonly class ListArtifactsQuery
{
    public function __construct(
        #[Assert\Length(max: 200)]
        public string $q = '',
        public bool $pending = false,
        public bool $vulnerable = false,
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
