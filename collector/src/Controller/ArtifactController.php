<?php

namespace App\Controller;

use App\Blacklist\Blacklist;
use App\Dto\ArtifactsQuery;
use App\Entity\Artifact;
use App\Repository\ArtifactRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/artifacts')]
final class ArtifactController
{
    public function __construct(
        private readonly ArtifactRepository $artifacts,
        private readonly Blacklist $blacklist,
    ) {
    }

    /** Recherche paginée : ?ecosystem=npm&q=lodash&pending=1&page=2 */
    #[Route('', methods: ['GET'])]
    public function index(#[MapQueryString(validationFailedStatusCode: 422)] ArtifactsQuery $query = new ArtifactsQuery()): JsonResponse
    {
        $qb = $this->artifacts->createQueryBuilder('a')->orderBy('a.name')->addOrderBy('a.version')->addOrderBy('a.filename');
        if ('' !== $query->ecosystem) {
            $qb->andWhere('a.ecosystem = :ecosystem')->setParameter('ecosystem', $query->ecosystem);
        }
        if ('' !== $q = trim($query->q)) {
            $qb->andWhere('LOWER(a.name) LIKE :q')->setParameter('q', '%'.addcslashes(mb_strtolower($q), '%_').'%');
        }
        if ($query->pending) {
            $qb->andWhere('a.export IS NULL');
        }
        $page = $query->page();
        $total = (int) (clone $qb)->select('COUNT(a.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<Artifact> $items */
        $items = $qb->setFirstResult($page->offset())->setMaxResults($page->pageSize)->getQuery()->getResult();

        return new JsonResponse($page->envelope(
            array_map(fn (Artifact $a) => Presenter::artifact($a, false, $this->blacklist->find($a->getEcosystem(), $a->getName(), $a->getVersion())), $items),
            $total,
        ));
    }
}
