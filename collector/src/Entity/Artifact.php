<?php

namespace App\Entity;

use App\Repository\ArtifactRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Sas\Bundle\Ecosystem;

/**
 * Un paquet téléchargé (une version npm, un fichier wheel, une archive Composer, une image Docker taguée).
 * Ses fichiers sont dans le stockage par empreinte ; « export » indique le bundle qui l'a transporté.
 */
#[ORM\Entity(repositoryClass: ArtifactRepository::class)]
#[ORM\Index(columns: ['ecosystem', 'name'])]
#[ORM\Index(columns: ['export_id'])]
class Artifact
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /** sha1(écosystème, nom, version, fichier) : identité stable d'un artefact */
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

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: Export::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Export $export = null;

    /**
     * Vulnérabilités connues (OSV.dev) à la dernière analyse.
     *
     * @var list<array{id: string, aliases: list<string>, summary: string, severity: string, fixed: list<string>, url: string}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $vulnerabilities = null;

    /** Gravité la plus élevée (critical, high, moderate, unknown, low), null si aucune vulnérabilité */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $severity = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $auditedAt = null;

    /** @var Collection<int, PackageList> */
    #[ORM\ManyToMany(targetEntity: PackageList::class, mappedBy: 'artifacts')]
    private Collection $lists;

    public function __construct(Ecosystem $ecosystem, string $name, string $version, ?string $filename)
    {
        $this->identity = self::identity($ecosystem, $name, $version, $filename);
        $this->ecosystem = $ecosystem;
        $this->name = $name;
        $this->version = $version;
        $this->filename = $filename;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->lists = new ArrayCollection();
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

    /**
     * Contenu (re)téléchargé. Si les fichiers changent (tag Docker déplacé, paquet republié),
     * l'artefact redevient « à exporter ».
     *
     * @param list<string>         $blobs
     * @param array<string, mixed> $metadata
     */
    public function update(array $blobs, int $size, array $metadata): void
    {
        if ($blobs !== $this->blobs) {
            $this->export = null;
            $this->updatedAt = new \DateTimeImmutable();
        }
        $this->blobs = $blobs;
        $this->size = (string) $size;
        $this->metadata = $metadata;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getExport(): ?Export
    {
        return $this->export;
    }

    public function setExport(?Export $export): void
    {
        $this->export = $export;
    }

    /** @return list<array{id: string, aliases: list<string>, summary: string, severity: string, fixed: list<string>, url: string}> */
    public function getVulnerabilities(): array
    {
        return $this->vulnerabilities ?? [];
    }

    public function getSeverity(): ?string
    {
        return $this->severity;
    }

    public function getAuditedAt(): ?\DateTimeImmutable
    {
        return $this->auditedAt;
    }

    /** @param list<array{id: string, aliases: list<string>, summary: string, severity: string, fixed: list<string>, url: string}> $vulnerabilities */
    public function setAudit(array $vulnerabilities, ?string $severity): void
    {
        $this->vulnerabilities = $vulnerabilities;
        $this->severity = $severity;
        $this->auditedAt = new \DateTimeImmutable();
    }

    /** @return Collection<int, PackageList> */
    public function getLists(): Collection
    {
        return $this->lists;
    }

    /** @return array{ecosystem: string, name: string, version: string, filename: string|null, blobs: list<string>, metadata: array<string, mixed>} */
    public function toManifest(): array
    {
        return [
            'ecosystem' => $this->ecosystem->value,
            'name' => $this->name,
            'version' => $this->version,
            'filename' => $this->filename,
            'blobs' => $this->blobs,
            // Alertes de sécurité connues à l'export : Romulus les affiche sur le réseau isolé
            'metadata' => null === $this->auditedAt ? $this->metadata : $this->metadata + ['security' => [
                'auditedAt' => $this->auditedAt->format(\DATE_ATOM),
                'severity' => $this->severity,
                'vulnerabilities' => $this->vulnerabilities ?? [],
            ]],
        ];
    }
}
