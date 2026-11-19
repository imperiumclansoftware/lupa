<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Pagination de toute liste renvoyée par l'API : 100 éléments au plus par page (guidelines). */
final readonly class PageQuery
{
    public const int MAX = 100;

    public function __construct(
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Range(min: 1, max: self::MAX)]
        public int $pageSize = self::MAX,
    ) {
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }

    /**
     * Enveloppe commune : éléments de la page, nombre total, page et taille.
     *
     * @param list<mixed> $items
     *
     * @return array{items: list<mixed>, total: int, page: int, pageSize: int}
     */
    public function envelope(array $items, int $total): array
    {
        return ['items' => $items, 'total' => $total, 'page' => $this->page, 'pageSize' => $this->pageSize];
    }

    /**
     * Découpe une liste déjà calculée (agrégats faits en PHP sur un ensemble borné).
     *
     * @param list<mixed> $all
     *
     * @return array{items: list<mixed>, total: int, page: int, pageSize: int}
     */
    public function slice(array $all): array
    {
        return $this->envelope(\array_slice($all, $this->offset(), $this->pageSize), \count($all));
    }
}
