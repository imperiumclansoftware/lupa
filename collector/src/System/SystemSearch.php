<?php

namespace App\System;

use App\Job\JobContext;
use App\Search\Glob;
use Doctrine\DBAL\Connection;
use Sas\Bundle\DebVersion;
use Sas\Bundle\Distributions;
use Sas\Bundle\Ecosystem;
use Sas\Bundle\RpmVersion;

/**
 * Recherche dans les index Debian et RPM (formulaire de liste) : même index que la résolution,
 * architecture et sections par défaut de la distribution.
 */
final class SystemSearch
{
    /** @var array<string, DebRepository|RpmRepository> */
    private array $loaded = [];

    public function __construct(
        private readonly DebIndex $deb,
        private readonly RpmIndex $rpm,
        private readonly Connection $db,
    ) {
    }

    /** @return list<array{name: string, description: string|null, version: string|null, info: string|null}> */
    public function search(Ecosystem $ecosystem, ?string $distribution, string $query, int $limit = 20): array
    {
        $repository = $this->repository($ecosystem, $distribution);
        $query = strtolower(trim($query));
        $exact = $prefix = $other = [];
        foreach ($repository->names() as $name) {
            $lower = strtolower($name);
            if ($lower === $query) {
                $exact[] = $name;
            } elseif (str_starts_with($lower, $query)) {
                $prefix[] = $name;
            } elseif (str_contains($lower, $query)) {
                $other[] = $name;
            }
        }
        sort($prefix);
        sort($other);
        $results = [];
        foreach (\array_slice([...$exact, ...$prefix, ...$other], 0, $limit) as $name) {
            $id = $repository->versions($name)[0];
            $p = $repository instanceof RpmRepository ? $repository->package($id) : $repository->packages[$id];
            $results[] = [
                'name' => $name,
                'description' => '' !== $p['d'] ? $p['d'] : null,
                'version' => $repository instanceof RpmRepository ? RpmRepository::evr($p) : $p['v'],
                'info' => null,
            ];
        }

        return $results;
    }

    /** @return array{versions: list<string>, latest: string|null} */
    public function versions(Ecosystem $ecosystem, ?string $distribution, string $name): array
    {
        $repository = $this->repository($ecosystem, $distribution);
        $versions = [];
        foreach ($repository->versions($name) as $id) {
            $p = $repository instanceof RpmRepository ? $repository->package($id) : $repository->packages[$id];
            $versions[] = $repository instanceof RpmRepository ? RpmRepository::evr($p) : $p['v'];
        }
        $versions = array_values(array_unique($versions));
        usort($versions, Ecosystem::Deb === $ecosystem ? static fn ($a, $b) => DebVersion::compare($b, $a) : static fn ($a, $b) => RpmVersion::compare($b, $a));

        return ['versions' => $versions, 'latest' => $versions[0] ?? null];
    }

    /** @return list<string> */
    public function names(Ecosystem $ecosystem, ?string $distribution, string $pattern): array
    {
        $names = array_values(array_filter($this->repository($ecosystem, $distribution)->names(), static fn (string $n) => Glob::matches($pattern, $n)));
        sort($names);

        return $names;
    }

    private function repository(Ecosystem $ecosystem, ?string $distribution): DebRepository|RpmRepository
    {
        $distribution = $distribution && Distributions::find($distribution) ? $distribution : array_key_first(Distributions::of($ecosystem));
        $dist = Distributions::find($distribution);
        if ($dist['ecosystem'] !== $ecosystem) {
            throw new \InvalidArgumentException('Distribution sans rapport avec cet écosystème.');
        }
        $arch = array_key_first($dist['architectures']);
        $sections = array_keys(array_filter($dist['sections'], static fn (array $s) => $s['default']));
        $context = new JobContext($this->db, 0);

        return $this->loaded[$distribution] ??= Ecosystem::Deb === $ecosystem
            ? $this->deb->load($distribution, $arch, $sections, $context)
            : $this->rpm->load($distribution, $arch, $sections, $context);
    }
}
