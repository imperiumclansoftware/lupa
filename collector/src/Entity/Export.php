<?php

namespace App\Entity;

use App\Repository\ExportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Bundle écrit pour la clé USB. Les numéros se suivent : le miroir détecte un bundle oublié. */
#[ORM\Entity(repositoryClass: ExportRepository::class)]
class Export
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    private int $sequence;

    #[ORM\Column(length: 36)]
    private string $uuid;

    /** complet = tout le contenu du collecteur ; sinon seulement ce qui n'a jamais été exporté */
    #[ORM\Column]
    private bool $full;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $maxPartSize;

    #[ORM\Column(length: 120)]
    private string $baseName = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $files = [];

    #[ORM\Column(type: Types::BIGINT)]
    private string $size = '0';

    #[ORM\Column]
    private int $artifactCount = 0;

    #[ORM\Column]
    private int $blobCount = 0;

    /** @var array<string, int> nombre d'artefacts par écosystème */
    #[ORM\Column(type: Types::JSON)]
    private array $summary = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToOne(targetEntity: Job::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Job $job = null;

    public function __construct(int $sequence, bool $full, ?int $maxPartSize)
    {
        $this->sequence = $sequence;
        $this->uuid = self::uuid();
        $this->full = $full;
        $this->maxPartSize = null === $maxPartSize ? null : (string) $maxPartSize;
        $this->createdAt = new \DateTimeImmutable();
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr(\ord($b[6]) & 0x0F | 0x40);
        $b[8] = \chr(\ord($b[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function isFull(): bool
    {
        return $this->full;
    }

    public function getMaxPartSize(): ?int
    {
        return null === $this->maxPartSize ? null : (int) $this->maxPartSize;
    }

    public function getBaseName(): string
    {
        return $this->baseName;
    }

    /** @return list<string> */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * @param list<string>       $files
     * @param array<string, int> $summary
     */
    public function complete(string $baseName, array $files, int $size, int $artifactCount, int $blobCount, array $summary): void
    {
        $this->baseName = $baseName;
        $this->files = $files;
        $this->size = (string) $size;
        $this->artifactCount = $artifactCount;
        $this->blobCount = $blobCount;
        $this->summary = $summary;
    }

    public function getSize(): int
    {
        return (int) $this->size;
    }

    public function getArtifactCount(): int
    {
        return $this->artifactCount;
    }

    public function getBlobCount(): int
    {
        return $this->blobCount;
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

    public function getJob(): ?Job
    {
        return $this->job;
    }

    public function setJob(?Job $job): void
    {
        $this->job = $job;
    }
}
