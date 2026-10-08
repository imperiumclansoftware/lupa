<?php

namespace App\Entity;

use App\Repository\PackageListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Sas\Bundle\Ecosystem;

/**
 * Liste de paquets à collecter pour un écosystème : des spécifications saisies (une par ligne)
 * et/ou un fichier de verrouillage importé (package-lock.json, composer.lock, requirements.txt).
 */
#[ORM\Entity(repositoryClass: PackageListRepository::class)]
class PackageList
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 16, enumType: Ecosystem::class)]
    private Ecosystem $ecosystem;

    #[ORM\Column(type: Types::TEXT)]
    private string $specs = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lockfileName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lockfile = null;

    /** @var array<string, mixed> options propres à l'écosystème (plateformes, versions de Python…) */
    #[ORM\Column(type: Types::JSON)]
    private array $options = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** @var Collection<int, Artifact> */
    #[ORM\ManyToMany(targetEntity: Artifact::class, inversedBy: 'lists')]
    #[ORM\JoinTable(name: 'package_list_artifact')]
    private Collection $artifacts;

    public function __construct(string $name, Ecosystem $ecosystem)
    {
        $this->name = $name;
        $this->ecosystem = $ecosystem;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->artifacts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getEcosystem(): Ecosystem
    {
        return $this->ecosystem;
    }

    public function getSpecs(): string
    {
        return $this->specs;
    }

    /** @return list<string> lignes non vides, sans les commentaires */
    public function getSpecLines(): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $this->specs) ?: [] as $line) {
            $line = trim((string) preg_replace('/(^|\s)#.*$/', '', $line));
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return array_values(array_unique($lines));
    }

    public function setSpecs(string $specs): void
    {
        $this->specs = $specs;
        $this->touch();
    }

    public function getLockfileName(): ?string
    {
        return $this->lockfileName;
    }

    public function getLockfile(): ?string
    {
        return $this->lockfile;
    }

    public function setLockfile(?string $name, ?string $content): void
    {
        $this->lockfileName = $name;
        $this->lockfile = $content;
        $this->touch();
    }

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @param array<string, mixed> $options */
    public function setOptions(array $options): void
    {
        $this->options = $options;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    /** @param list<Artifact> $artifacts contenu complet après une résolution réussie */
    public function replaceArtifacts(array $artifacts): void
    {
        $this->artifacts->clear();
        foreach ($artifacts as $artifact) {
            $this->artifacts->add($artifact);
        }
        $this->resolvedAt = new \DateTimeImmutable();
    }

    /** @return Collection<int, Artifact> */
    public function getArtifacts(): Collection
    {
        return $this->artifacts;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
