<?php

namespace App\Entity;

use App\Repository\AdvisoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Fiche de vulnérabilité OSV en cache (GHSA-…, PYSEC-…, CVE-…). */
#[ORM\Entity(repositoryClass: AdvisoryRepository::class)]
class Advisory
{
    #[ORM\Id, ORM\Column(length: 80)]
    private string $id;

    #[ORM\Column(length: 40)]
    private string $modified = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $data = [];

    #[ORM\Column]
    private \DateTimeImmutable $fetchedAt;

    public function __construct(string $id)
    {
        $this->id = $id;
        $this->fetchedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getModified(): string
    {
        return $this->modified;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    /** @param array<string, mixed> $data */
    public function update(string $modified, array $data): void
    {
        $this->modified = $modified;
        $this->data = $data;
        $this->fetchedAt = new \DateTimeImmutable();
    }
}
