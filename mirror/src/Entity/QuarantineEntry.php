<?php

namespace App\Entity;

use App\Repository\QuarantineEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Sas\Bundle\Ecosystem;

/**
 * Version interdite par la liste noire d'un collecteur : déjà présente ou non, elle n'est plus servie
 * par les registres du miroir (quarantaine), jusqu'à son retrait de la liste.
 */
#[ORM\Entity(repositoryClass: QuarantineEntryRepository::class)]
#[ORM\Index(columns: ['collector_id'])]
class QuarantineEntry
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $collectorId;

    #[ORM\Column(length: 120)]
    private string $collectorName;

    #[ORM\Column(length: 16, enumType: Ecosystem::class)]
    private Ecosystem $ecosystem;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 128)]
    private string $version;

    #[ORM\Column(length: 500)]
    private string $reason;

    /** Révision de la liste noire qui l'a apportée */
    #[ORM\Column]
    private int $revision;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $collectorId, string $collectorName, Ecosystem $ecosystem, string $name, string $version, string $reason, int $revision)
    {
        $this->collectorId = $collectorId;
        $this->collectorName = $collectorName;
        $this->ecosystem = $ecosystem;
        $this->name = $name;
        $this->version = $version;
        $this->reason = $reason;
        $this->revision = $revision;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCollectorId(): string
    {
        return $this->collectorId;
    }

    public function getCollectorName(): string
    {
        return $this->collectorName;
    }

    public function getEcosystem(): Ecosystem
    {
        return $this->ecosystem;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
