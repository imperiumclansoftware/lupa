<?php

namespace App\System;

use App\Repository\CustomDistributionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Distributions personnalisées ajoutées au catalogue (Sas\Bundle\Distributions::extend, branché par le
 * noyau). Relues entre deux requêtes ou deux tâches du worker.
 */
/** @phpstan-import-type Distribution from \Sas\Bundle\Distributions */
#[Autoconfigure(public: true)]
final class CustomDistributions implements ResetInterface
{
    /** @var array<string, Distribution>|null */
    private ?array $all = null;

    public function __construct(private readonly CustomDistributionRepository $repository)
    {
    }

    /** @return array<string, Distribution> */
    public function all(): array
    {
        if (null === $this->all) {
            $this->all = [];
            try {
                foreach ($this->repository->findBy([], ['slug' => 'ASC']) as $distribution) {
                    $this->all[$distribution->getSlug()] = $distribution->toDistribution();
                }
            } catch (\Throwable) {
                // Base pas encore migrée (premier démarrage) : seulement les distributions intégrées
                $this->all = [];
            }
        }

        return $this->all;
    }

    public function reset(): void
    {
        $this->all = null;
    }
}
