<?php

namespace App\Entity;

use App\Repository\BlacklistEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Sas\Bundle\Ecosystem;
use Sas\Bundle\PackageKey;

/**
 * Version précise interdite : jamais téléchargée par Rémus, jamais exportée, et mise en quarantaine
 * par Romulus (la liste voyage dans chaque bundle).
 */
#[ORM\Entity(repositoryClass: BlacklistEntryRepository::class)]
class BlacklistEntry
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /** sha1 de PackageKey : une seule entrée par version, quelle que soit son écriture */
    #[ORM\Column(length: 40, unique: true)]
    private string $identity;

    #[ORM\Column(length: 16, enumType: Ecosystem::class)]
    private Ecosystem $ecosystem;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 128)]
    private string $version;

    #[ORM\Column(length: 500)]
    private string $reason;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Ecosystem $ecosystem, string $name, string $version, string $reason)
    {
        $this->ecosystem = $ecosystem;
        $this->name = PackageKey::name($ecosystem, $name);
        $this->version = trim($version);
        $this->reason = trim($reason);
        $this->identity = self::identity($ecosystem, $name, $version);
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function identity(Ecosystem $ecosystem, string $name, string $version): string
    {
        return sha1(PackageKey::of($ecosystem, $name, $version));
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

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return array{ecosystem: string, name: string, version: string, reason: string} */
    public function toManifest(): array
    {
        return ['ecosystem' => $this->ecosystem->value, 'name' => $this->name, 'version' => $this->version, 'reason' => $this->reason];
    }
}
