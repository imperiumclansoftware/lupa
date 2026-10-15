<?php

namespace App\Repository;

use App\Entity\BlacklistEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BlacklistEntry> */
class BlacklistEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlacklistEntry::class);
    }
}
