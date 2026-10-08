<?php

namespace App\Entity;

use App\Repository\ImportedBundleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Bundle importé avec succès (historique et détection des bundles manquants). */
#[ORM\Entity(repositoryClass: ImportedBundleRepository::class)]
#[ORM\Index(columns: ['collector_id', 'sequence'])]
class ImportedBundle
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(length: 64)]
    private string $collectorId;

    #[ORM\Column(length: 120)]
    private string $collectorName;

    #[ORM\Column]
    private int $sequence;

    #[ORM\Column]
    private bool $full;

    #[ORM\Column(length: 255)]
    private string $source;

    #[ORM\Column]
    private int $artifactCount;

    #[ORM\Column]
    private int $newBlobCount;

    #[ORM\Column(type: Types::BIGINT)]
    private string $size;

    /** @var array<string, int> */
    #[ORM\Column(type: Types::JSON)]
    private array $summary;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    /** @param array<string, int> $summary */
    public function __construct(string $uuid, string $collectorId, string $collectorName, int $sequence, bool $full, string $source, int $artifactCount, int $newBlobCount, int $size, array $summary, \DateTimeImmutable $createdAt)
    {
        $this->uuid = $uuid;
        $this->collectorId = $collectorId;
        $this->collectorName = $collectorName;
        $this->sequence = $sequence;
        $this->full = $full;
        $this->source = $source;
        $this->artifactCount = $artifactCount;
        $this->newBlobCount = $newBlobCount;
        $this->size = (string) $size;
        $this->summary = $summary;
        $this->createdAt = $createdAt;
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getCollectorId(): string
    {
        return $this->collectorId;
    }

    public function getCollectorName(): string
    {
        return $this->collectorName;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function isFull(): bool
    {
        return $this->full;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getArtifactCount(): int
    {
        return $this->artifactCount;
    }

    public function getNewBlobCount(): int
    {
        return $this->newBlobCount;
    }

    public function getSize(): int
    {
        return (int) $this->size;
    }

    /** @return array<string, int> */
    public function getSummary(): array
    {
        return $this->summary;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }
}
