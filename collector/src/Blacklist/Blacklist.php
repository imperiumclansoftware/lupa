<?php

namespace App\Blacklist;

use App\Entity\BlacklistEntry;
use App\Entity\Setting;
use App\Job\JobContext;
use App\Repository\BlacklistEntryRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Sas\Bundle\Ecosystem;
use Symfony\Contracts\Service\ResetInterface;
use Sas\Bundle\PackageKey;

/**
 * Liste noire : versions précises interdites de téléchargement. Chaque modification incrémente la
 * révision ; la liste complète part dans le bundle suivant et Romulus applique la plus récente.
 */
final class Blacklist implements ResetInterface
{
    private const string REVISION = 'blacklist.revision';
    private const string UPDATED = 'blacklist.updatedAt';
    private const string EXPORTED = 'blacklist.exportedRevision';

    /** @var array<string, BlacklistEntry>|null */
    private ?array $index = null;

    /** @var list<string> versions écartées pendant la tâche en cours */
    private array $blocked = [];

    public function __construct(
        private readonly BlacklistEntryRepository $entries,
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {
    }

    /** Entre deux tâches du worker : relire la base (la liste a pu changer). */
    public function reset(): void
    {
        $this->index = null;
        $this->blocked = [];
    }

    /**
     * Artefacts déjà collectés qui sont sur la liste noire.
     *
     * @return array<int, BlacklistEntry> identifiant d'artefact => entrée
     */
    public function collected(bool $pendingOnly = false): array
    {
        $ids = [];
        foreach ($this->all() as $entry) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, version FROM artifact WHERE ecosystem = ? AND name = ?'.($pendingOnly ? ' AND export_id IS NULL' : ''),
                [$entry->getEcosystem()->value, $entry->getName()],
            );
            foreach ($rows as $row) {
                if (PackageKey::version($entry->getEcosystem(), (string) $row['version']) === PackageKey::version($entry->getEcosystem(), $entry->getVersion())) {
                    $ids[(int) $row['id']] = $entry;
                }
            }
        }

        return $ids;
    }

    /** @return list<BlacklistEntry> */
    public function all(): array
    {
        return $this->entries->findBy([], ['ecosystem' => 'ASC', 'name' => 'ASC', 'version' => 'ASC']);
    }

    public function find(Ecosystem $ecosystem, string $name, string $version): ?BlacklistEntry
    {
        if (null === $this->index) {
            $this->index = [];
            foreach ($this->entries->findAll() as $entry) {
                $this->index[PackageKey::of($entry->getEcosystem(), $entry->getName(), $entry->getVersion())] = $entry;
            }
        }

        return $this->index[PackageKey::of($ecosystem, $name, $version)] ?? null;
    }

    /**
     * À appeler par les résolveurs avant chaque téléchargement : vrai si la version est interdite
     * (elle est alors notée au journal et comptée dans le résumé de la tâche).
     */
    public function blocks(Ecosystem $ecosystem, string $name, string $version, JobContext $context): bool
    {
        $entry = $this->find($ecosystem, $name, $version);
        if (!$entry) {
            return false;
        }
        $label = $name.('docker' === $ecosystem->value ? ':' : ' ').$version;
        if (!\in_array($label, $this->blocked, true)) {
            $this->blocked[] = $label;
            $context->log(sprintf('BLOQUÉ %s : liste noire%s.', $label, '' !== $entry->getReason() ? ' ('.$entry->getReason().')' : ''));
        }

        return true;
    }

    /** @return list<string> versions écartées depuis le dernier appel */
    public function takeBlocked(): array
    {
        [$blocked, $this->blocked] = [$this->blocked, []];

        return $blocked;
    }

    public function add(Ecosystem $ecosystem, string $name, string $version, string $reason): BlacklistEntry
    {
        $identity = BlacklistEntry::identity($ecosystem, $name, $version);
        if ($existing = $this->entries->findOneBy(['identity' => $identity])) {
            return $existing;
        }
        $entry = new BlacklistEntry($ecosystem, $name, $version, $reason);
        $this->em->persist($entry);
        $this->touch();
        $this->em->flush();

        return $entry;
    }

    public function remove(BlacklistEntry $entry): void
    {
        $this->em->remove($entry);
        $this->touch();
        $this->em->flush();
    }

    public function revision(): int
    {
        return (int) $this->setting(self::REVISION)?->getValue();
    }

    public function exportedRevision(): int
    {
        return (int) $this->setting(self::EXPORTED)?->getValue();
    }

    /** Vrai si la liste a changé depuis le dernier bundle : un export est alors utile, même sans nouveau paquet. */
    public function pendingExport(): bool
    {
        return $this->revision() > $this->exportedRevision();
    }

    public function markExported(int $revision): void
    {
        $this->set(self::EXPORTED, (string) max($revision, $this->exportedRevision()));
    }

    /**
     * Liste transportée dans le manifeste (null tant qu'elle n'a jamais été utilisée).
     *
     * @return array{revision: int, updatedAt: string, entries: list<array{ecosystem: string, name: string, version: string, reason: string}>}|null
     */
    public function toManifest(): ?array
    {
        if (0 === $revision = $this->revision()) {
            return null;
        }

        return [
            'revision' => $revision,
            'updatedAt' => (string) $this->setting(self::UPDATED)?->getValue(),
            'entries' => array_map(static fn (BlacklistEntry $e) => $e->toManifest(), $this->all()),
        ];
    }

    public function updatedAt(): ?string
    {
        return $this->setting(self::UPDATED)?->getValue();
    }

    private function touch(): void
    {
        $this->index = null;
        $this->set(self::REVISION, (string) ($this->revision() + 1));
        $this->set(self::UPDATED, (new \DateTimeImmutable())->format(\DATE_ATOM));
    }

    private function setting(string $name): ?Setting
    {
        return $this->em->find(Setting::class, $name);
    }

    private function set(string $name, string $value): void
    {
        if ($setting = $this->setting($name)) {
            $setting->setValue($value);
        } else {
            $this->em->persist(new Setting($name, $value));
        }
    }
}
