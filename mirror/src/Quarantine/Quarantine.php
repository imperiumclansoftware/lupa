<?php

namespace App\Quarantine;

use App\Entity\Package;
use App\Entity\QuarantineEntry;
use App\Entity\Setting;
use App\Repository\QuarantineEntryRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Sas\Bundle\Ecosystem;
use Symfony\Contracts\Service\ResetInterface;
use Sas\Bundle\PackageKey;

/**
 * Quarantaine : versions de la liste noire des collecteurs. Elles restent stockées (pour l'historique
 * et un éventuel retrait de la liste) mais les registres refusent de les servir.
 */
final class Quarantine implements ResetInterface
{
    /** @var array<string, QuarantineEntry>|null */
    private ?array $index = null;

    /** @var array<string, QuarantineEntry>|null empreintes des manifestes Docker en quarantaine */
    private ?array $digests = null;

    public function __construct(
        private readonly QuarantineEntryRepository $entries,
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {
    }

    /** Entre deux tâches du worker : relire la base (la liste a pu changer). */
    public function reset(): void
    {
        $this->index = $this->digests = null;
    }

    /** @return list<QuarantineEntry> */
    public function all(): array
    {
        return $this->entries->findBy([], ['ecosystem' => 'ASC', 'name' => 'ASC', 'version' => 'ASC']);
    }

    public function find(Ecosystem $ecosystem, string $name, string $version): ?QuarantineEntry
    {
        return $this->index()[PackageKey::of($ecosystem, $name, $version)] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->index();
    }

    /** @return array<string, QuarantineEntry> */
    private function index(): array
    {
        if (null === $this->index) {
            $this->index = [];
            foreach ($this->entries->findAll() as $entry) {
                $this->index[PackageKey::of($entry->getEcosystem(), $entry->getName(), $entry->getVersion())] = $entry;
            }
        }

        return $this->index;
    }

    public function forPackage(Package $package): ?QuarantineEntry
    {
        return $this->find($package->getEcosystem(), $package->getName(), $package->getVersion());
    }

    /**
     * Fichier (archive npm, wheel, dist Composer) appartenant à une version en quarantaine.
     */
    public function blob(Ecosystem $ecosystem, string $sha256): ?QuarantineEntry
    {
        if ($this->isEmpty()) {
            return null;
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT p.name, p.version FROM package p, json_each(p.blobs) b WHERE p.ecosystem = ? AND b.value = ?',
            [$ecosystem->value, $sha256],
        );
        foreach ($rows as $row) {
            if ($entry = $this->find($ecosystem, (string) $row['name'], (string) $row['version'])) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Manifeste Docker en quarantaine : celui d'une étiquette interdite, ou l'une de ses plateformes
     * (même image tirée par son empreinte ou sous une autre étiquette).
     */
    public function digest(string $digest): ?QuarantineEntry
    {
        if (null === $this->digests) {
            $this->digests = [];
            foreach ($this->isEmpty() ? [] : $this->all() as $entry) {
                if (Ecosystem::Docker !== $entry->getEcosystem()) {
                    continue;
                }
                if (str_starts_with($entry->getVersion(), 'sha256:')) {
                    $this->digests[$entry->getVersion()] = $entry;
                }
                $rows = $this->db->fetchAllAssociative("SELECT version, metadata FROM package WHERE ecosystem = 'docker' AND name = ?", [$entry->getName()]);
                foreach ($rows as $row) {
                    if ($row['version'] !== $entry->getVersion()) {
                        continue;
                    }
                    $metadata = json_decode((string) $row['metadata'], true) ?: [];
                    foreach ([$metadata['digest'] ?? null, ...array_column($metadata['manifests'] ?? [], 'digest')] as $d) {
                        if (\is_string($d)) {
                            $this->digests[$d] = $entry;
                        }
                    }
                }
            }
        }

        return $this->digests[$digest] ?? null;
    }

    /** @return array{revision: int, updatedAt: string, collectorName: string}|null */
    public function revisionOf(string $collectorId): ?array
    {
        $setting = $this->em->find(Setting::class, 'blacklist.'.$collectorId);

        return $setting ? json_decode($setting->getValue(), true) : null;
    }

    /**
     * Applique la liste noire reçue d'un collecteur si elle est plus récente que celle connue.
     *
     * @param array{revision: int, updatedAt: string, entries: list<array{ecosystem: string, name: string, version: string, reason: string}>} $blacklist
     *
     * @return array{applied: bool, entries: int, added: int, removed: int, affected: int}|null null si la révision est déjà connue
     */
    public function apply(string $collectorId, string $collectorName, array $blacklist): ?array
    {
        $known = $this->revisionOf($collectorId);
        if (null !== $known && $known['revision'] >= $blacklist['revision']) {
            return null;
        }

        $existing = [];
        foreach ($this->entries->findBy(['collectorId' => $collectorId]) as $entry) {
            $existing[PackageKey::of($entry->getEcosystem(), $entry->getName(), $entry->getVersion())] = $entry;
        }
        $added = 0;
        $kept = [];
        foreach ($blacklist['entries'] as $item) {
            $ecosystem = Ecosystem::from($item['ecosystem']);
            $key = PackageKey::of($ecosystem, $item['name'], $item['version']);
            if (isset($kept[$key])) {
                continue;
            }
            $kept[$key] = true;
            if (!isset($existing[$key])) {
                $this->em->persist(new QuarantineEntry($collectorId, $collectorName, $ecosystem, PackageKey::name($ecosystem, $item['name']), $item['version'], $item['reason'], $blacklist['revision']));
                ++$added;
            }
        }
        $removed = 0;
        foreach ($existing as $key => $entry) {
            if (!isset($kept[$key])) {
                $this->em->remove($entry);
                ++$removed;
            }
        }

        $value = (string) json_encode(['revision' => $blacklist['revision'], 'updatedAt' => $blacklist['updatedAt'], 'collectorName' => $collectorName]);
        if ($setting = $this->em->find(Setting::class, 'blacklist.'.$collectorId)) {
            $setting->setValue($value);
        } else {
            $this->em->persist(new Setting('blacklist.'.$collectorId, $value));
        }
        $this->em->flush();
        $this->index = $this->digests = null;

        return ['applied' => true, 'entries' => \count($kept), 'added' => $added, 'removed' => $removed, 'affected' => \count($this->quarantinedPackages())];
    }

    /**
     * Paquets présents dans le miroir et actuellement en quarantaine.
     *
     * @return list<array{ecosystem: string, name: string, version: string, files: int, entry: QuarantineEntry}>
     */
    public function quarantinedPackages(): array
    {
        $found = [];
        foreach ($this->all() as $entry) {
            $rows = $this->db->fetchAllAssociative('SELECT version, COUNT(*) AS files FROM package WHERE ecosystem = ? AND name = ? GROUP BY version', [$entry->getEcosystem()->value, $entry->getName()]);
            foreach ($rows as $row) {
                if (PackageKey::version($entry->getEcosystem(), (string) $row['version']) === PackageKey::version($entry->getEcosystem(), $entry->getVersion())) {
                    $found[] = ['ecosystem' => $entry->getEcosystem()->value, 'name' => $entry->getName(), 'version' => (string) $row['version'], 'files' => (int) $row['files'], 'entry' => $entry];
                }
            }
        }

        return $found;
    }
}
