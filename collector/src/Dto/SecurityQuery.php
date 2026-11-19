<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Alertes de sécurité : niveau affiché et pagination. */
final class SecurityQuery
{
    public function __construct(
        #[Assert\Choice(choices: ['all', 'critical', 'warning', 'low'])]
        public string $level = 'all',
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
