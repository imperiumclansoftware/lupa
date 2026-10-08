<?php

namespace App\Entity;

use App\Repository\BlobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Fichier du stockage par empreinte ; « export » = premier bundle qui l'a transporté. */
#[ORM\Entity(repositoryClass: BlobRepository::class)]
class Blob
{
    #[ORM\Id, ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(type: Types::BIGINT)]
    private string $size;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Export::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Export $export = null;

    public function __construct(string $sha256, int $size)
    {
        $this->sha256 = $sha256;
        $this->size = (string) $size;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getSize(): int
    {
        return (int) $this->size;
    }

    public function getExport(): ?Export
    {
        return $this->export;
    }

    public function setExport(?Export $export): void
    {
        $this->export = $export;
    }
}
