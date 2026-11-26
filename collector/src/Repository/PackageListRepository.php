<?php

namespace App\Repository;

use App\Entity\PackageList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<PackageList> */
class PackageListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PackageList::class);
    }

    /**
     * Listes qui contiennent un artefact (requête sur la table de liaison, jamais périmée en mémoire).
     *
     * @return list<PackageList>
     */
    public function containing(Uuid $artifactId): array
    {
        /** @var list<PackageList> */
        return $this->createQueryBuilder('l')
            ->innerJoin('l.artifacts', 'a')
            ->where('a.id = :artifact')
            ->setParameter('artifact', $artifactId, UuidType::NAME)
            ->orderBy('l.name')
            ->getQuery()->getResult();
    }
}
