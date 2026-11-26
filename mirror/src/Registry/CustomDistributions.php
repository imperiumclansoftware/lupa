<?php

namespace App\Registry;

use Doctrine\DBAL\Connection;
use Sas\Bundle\Ecosystem;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Distributions personnalisées de Rémus, apprises des paquets importés (métadonnée « distributionInfo ») et
 * ajoutées au catalogue (Sas\Bundle\Distributions::extend, branché par le noyau). Aucun changement du format
 * des bundles : un Romulus plus ancien importe ces paquets sans les servir.
 */
/** @phpstan-import-type Distribution from \Sas\Bundle\Distributions */
#[Autoconfigure(public: true)]
final class CustomDistributions implements ResetInterface
{
    /** @var array<string, Distribution>|null */
    private ?array $all = null;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, Distribution> */
    public function all(): array
    {
        if (null !== $this->all) {
            return $this->all;
        }
        $this->all = [];
        try {
            // Description la plus récente de chaque distribution (SQLite : colonnes de la ligne du MAX)
            $rows = $this->db->fetchAllAssociative(
                "SELECT substr(filename, 1, instr(filename, '/') - 1) AS distribution, json_extract(metadata, '$.distributionInfo') AS info, MAX(updated_at) AS updated
                 FROM package WHERE ecosystem IN ('deb', 'rpm') AND json_extract(metadata, '$.distributionInfo') IS NOT NULL
                 GROUP BY distribution"
            );
        } catch (\Throwable) {
            return $this->all; // base pas encore migrée
        }
        foreach ($rows as $row) {
            $id = \is_string($row['distribution']) ? $row['distribution'] : '';
            $info = \is_string($row['info']) ? json_decode($row['info'], true) : null;
            if (!preg_match('/^[a-z0-9-]+$/', $id) || !\is_array($info) || !\is_string($info['ecosystem'] ?? null) || null === $ecosystem = Ecosystem::tryFrom($info['ecosystem'])) {
                continue;
            }
            $text = static fn (mixed $v): string => \is_string($v) || \is_int($v) ? (string) $v : '';
            $list = static fn (mixed $v): array => \is_array($v) ? array_values(array_filter(array_map($text, $v), static fn (string $s) => '' !== $s)) : [];
            $architectures = $list($info['architectures'] ?? []);
            $sections = $list($info['sections'] ?? []);
            $this->all[$id] = [
                'ecosystem' => $ecosystem,
                'label' => $text($info['label'] ?? null) ?: $id,
                'codename' => $text($info['codename'] ?? null) ?: $id,
                'release' => $text($info['release'] ?? null),
                'eol' => (bool) ($info['eol'] ?? false),
                'architectures' => array_combine($architectures, $architectures),
                'sections' => array_combine($sections, array_map(static fn (string $s) => ['label' => $s, 'default' => true], $sections)),
                'custom' => true,
                'modules' => false,
            ];
        }

        return $this->all;
    }

    public function reset(): void
    {
        $this->all = null;
    }
}
