<?php

namespace App\Controller;

use App\Entity\Artifact;
use App\Repository\ArtifactRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/artifacts')]
final class ArtifactController
{
    private const int PAGE_SIZE = 100;

    public function __construct(private readonly ArtifactRepository $artifacts)
    {
    }

    /** Recherche paginée : ?ecosystem=npm&q=lodash&pending=1&page=2 */
    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $qb = $this->artifacts->createQueryBuilder('a')->orderBy('a.name')->addOrderBy('a.version')->addOrderBy('a.filename');
        if ($ecosystem = $request->query->getString('ecosystem')) {
            $qb->andWhere('a.ecosystem = :ecosystem')->setParameter('ecosystem', $ecosystem);
        }
        if ('' !== $q = trim($request->query->getString('q'))) {
            $qb->andWhere('LOWER(a.name) LIKE :q')->setParameter('q', '%'.addcslashes(mb_strtolower($q), '%_').'%');
        }
        if ($request->query->getBoolean('pending')) {
            $qb->andWhere('a.export IS NULL');
        }
        $total = (int) (clone $qb)->select('COUNT(a.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $page = max(1, $request->query->getInt('page', 1));
        /** @var list<Artifact> $items */
        $items = $qb->setFirstResult(($page - 1) * self::PAGE_SIZE)->setMaxResults(self::PAGE_SIZE)->getQuery()->getResult();

        return new JsonResponse([
            'items' => array_map(Presenter::artifact(...), $items),
            'total' => $total,
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
        ]);
    }
}
