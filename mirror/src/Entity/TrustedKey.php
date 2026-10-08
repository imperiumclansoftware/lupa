<?php

namespace App\Entity;

use App\Repository\TrustedKeyRepository;
use Doctrine\ORM\Mapping as ORM;
use Sas\Bundle\Signer;

/** Clé publique d'un collecteur dont les bundles sont acceptés. */
#[ORM\Entity(repositoryClass: TrustedKeyRepository::class)]
class TrustedKey
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $publicKey;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $publicKey, string $label)
    {
        if (!Signer::isPublicKey($publicKey)) {
            throw new \InvalidArgumentException('Clé publique invalide.');
        }
        $this->publicKey = trim($publicKey);
        $this->label = $label;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getFingerprint(): string
    {
        return Signer::fingerprint($this->publicKey);
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
