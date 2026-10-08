<?php

namespace App\Entity;

use App\Repository\PackageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Sas\Bundle\Ecosystem;

/**
 * Paquet servi par le miroir : une version npm, un fichier Python, une version Composer
 * ou une image Docker étiquetée. Ses fichiers sont dans le stockage par empreinte.
 */
#[ORM\Entity(repositoryClass: PackageRepository::class)]
#[ORM\Index(columns: ['ecosystem', 'name'])]
class Package
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40, unique: true)]
    private string $identity;

    #[ORM\Column(length: 16, enumType: Ecosystem::class)]
    private Ecosystem $ecosystem;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $version;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filename;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $blobs = [];

    #[ORM\Column(type: Types::BIGINT)]
    private string $size = '0';

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    #[ORM\ManyToOne(targetEntity: ImportedBundle::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ImportedBundle $bundle = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Ecosystem $ecosystem, string $name, string $version, ?string $filename)
    {
        $this->identity = self::identity($ecosystem, $name, $version, $filename);
        $this->ecosystem = $ecosystem;
        $this->name = $name;
        $this->version = $version;
        $this->filename = $filename;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public static function identity(Ecosystem $ecosystem, string $name, string $version, ?string $filename): string
    {
        return sha1(implode("\0", [$ecosystem->value, $name, $version, $filename ?? '']));
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    /** @return list<string> */
    public function getBlobs(): array
    {
        return $this->blobs;
    }

    public function getSize(): int
    {
        return (int) $this->size;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getBundle(): ?ImportedBundle
    {
        return $this->bundle;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param list<string>         $blobs
     * @param array<string, mixed> $metadata
     */
    public function update(array $blobs, int $size, array $metadata, ImportedBundle $bundle): void
    {
        if (null !== $this->id && $blobs !== $this->blobs) {
            $this->updatedAt = new \DateTimeImmutable();
        }
        $this->blobs = $blobs;
        $this->size = (string) $size;
        $this->metadata = $metadata;
        $this->bundle = $bundle;
    }
}
