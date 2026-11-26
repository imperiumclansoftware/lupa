<?php

namespace App\Entity;

use App\Repository\SourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Sas\Bundle\Ecosystem;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Dépôt privé utilisé en plus du dépôt officiel (ou à sa place) : Verdaccio, Nexus, Artifactory,
 * GitLab, Harbor, devpi, Satis… Le secret est chiffré (SecretBox) et n'est jamais renvoyé à l'interface.
 */
#[ORM\Entity(repositoryClass: SourceRepository::class)]
class Source
{
    public const string AUTH_NONE = 'none';
    public const string AUTH_BASIC = 'basic';
    public const string AUTH_TOKEN = 'token';

    #[ORM\Id, ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 16, enumType: Ecosystem::class)]
    private Ecosystem $ecosystem;

    #[ORM\Column(length: 120)]
    private string $label = '';

    #[ORM\Column(length: 500)]
    private string $url = '';

    /** npm : portée servie par ce dépôt (@acme) ; vide = remplace le registre public */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $scope = null;

    #[ORM\Column(length: 8)]
    private string $authType = self::AUTH_NONE;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $username = null;

    /** Secret chiffré (mot de passe ou jeton) */
    #[ORM\Column(length: 2000, nullable: true)]
    private ?string $secret = null;

    /** Remplace le dépôt officiel au lieu de s'y ajouter (miroir d'entreprise, réseau filtré) */
    #[ORM\Column]
    private bool $replacesDefault = false;

    #[ORM\Column]
    private bool $enabled = true;

    /** Debian, RPM : distribution à laquelle s'ajoute ce dépôt (debian-12, rocky-9…) */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $distribution = null;

    /** Debian : suite publiée par le dépôt (bookworm, bookworm-pgdg…) */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $suite = null;

    /**
     * Debian : composants à lire (main, stable…).
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $components = null;

    /** Debian, RPM : clé publique OpenPGP (ASCII armor) qui signe l'index ou les paquets du dépôt */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signingKey = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Ecosystem $ecosystem)
    {
        $this->id = Uuid::v7();
        $this->ecosystem = $ecosystem;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEcosystem(): Ecosystem
    {
        return $this->ecosystem;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /** Adresse sans barre finale */
    public function getUrl(): string
    {
        return $this->url;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function getAuthType(): string
    {
        return $this->authType;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function replacesDefault(): bool
    {
        return $this->replacesDefault;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function update(string $label, string $url, ?string $scope, bool $replacesDefault, bool $enabled): void
    {
        $this->label = trim($label);
        $this->url = rtrim(trim($url), '/');
        $this->scope = null === $scope || '' === trim($scope) ? null : strtolower(trim($scope));
        $this->replacesDefault = $replacesDefault;
        $this->enabled = $enabled;
    }

    /** @param string|null $secret secret chiffré ; null = inchangé */
    public function setAuth(string $type, ?string $username, ?string $secret): void
    {
        $this->authType = $type;
        $this->username = self::AUTH_NONE === $type || null === $username || '' === trim($username) ? null : trim($username);
        if (self::AUTH_NONE === $type) {
            $this->secret = null;
        } elseif (null !== $secret) {
            $this->secret = $secret;
        }
    }

    public function getDistribution(): ?string
    {
        return $this->distribution;
    }

    public function getSuite(): ?string
    {
        return $this->suite;
    }

    /** @return list<string> */
    public function getComponents(): array
    {
        return $this->components ?? [];
    }

    public function getSigningKey(): ?string
    {
        return $this->signingKey;
    }

    /**
     * Dépôt apt ou dnf tiers.
     *
     * @param list<string> $components
     */
    public function setSystem(string $distribution, ?string $suite, array $components, string $signingKey): void
    {
        $this->distribution = $distribution;
        $this->suite = $suite;
        $this->components = [] === $components ? null : $components;
        $this->signingKey = $signingKey;
    }

    public function host(): string
    {
        return strtolower((string) parse_url($this->url, \PHP_URL_HOST)).(($port = parse_url($this->url, \PHP_URL_PORT)) ? ':'.$port : '');
    }
}
