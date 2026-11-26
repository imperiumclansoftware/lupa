<?php

namespace App\Entity;

use App\Repository\CustomDistributionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Sas\Bundle\Ecosystem;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Distribution personnalisée (Réglages) : dérivée interne, distribution absente du catalogue de Lupa… Mêmes
 * informations qu'une distribution intégrée, avec sa propre clé de signature. Romulus l'apprend par les
 * métadonnées de ses paquets (« distributionInfo »).
 */
/** @phpstan-import-type Distribution from \Sas\Bundle\Distributions */
#[ORM\Entity(repositoryClass: CustomDistributionRepository::class)]
class CustomDistribution
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** Identifiant repris dans les chemins des paquets et les adresses de Romulus (debian-12-interne…) */
    #[ORM\Column(length: 32, unique: true)]
    private string $slug;

    #[ORM\Column(length: 16, enumType: Ecosystem::class)]
    private Ecosystem $ecosystem;

    #[ORM\Column(length: 120)]
    private string $label = '';

    #[ORM\Column(length: 40)]
    private string $release = '';

    /** Debian : suite servie par Romulus (Suite, Codename) */
    #[ORM\Column(length: 60)]
    private string $codename = '';

    #[ORM\Column]
    private bool $eol = false;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $architectures = [];

    /** @var list<string> Debian : composants ; RPM : noms des dépôts */
    #[ORM\Column(type: Types::JSON)]
    private array $sections = [];

    /**
     * Debian : [{url, suite}] ; RPM : [{name, url}] (url avec $basearch).
     *
     * @var list<array{url: string, suite?: string, name?: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $sources = [];

    #[ORM\Column(type: Types::TEXT)]
    private string $signingKey = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $slug, Ecosystem $ecosystem)
    {
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->ecosystem = $ecosystem;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getEcosystem(): Ecosystem
    {
        return $this->ecosystem;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getRelease(): string
    {
        return $this->release;
    }

    public function getCodename(): string
    {
        return $this->codename;
    }

    public function isEol(): bool
    {
        return $this->eol;
    }

    /** @return list<string> */
    public function getArchitectures(): array
    {
        return $this->architectures;
    }

    /** @return list<string> */
    public function getSections(): array
    {
        return $this->sections;
    }

    /** @return list<array{url: string, suite?: string, name?: string}> */
    public function getSources(): array
    {
        return $this->sources;
    }

    public function getSigningKey(): string
    {
        return $this->signingKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @param list<string>                                            $architectures
     * @param list<string>                                            $sections
     * @param list<array{url: string, suite?: string, name?: string}> $sources
     */
    public function update(string $label, string $release, string $codename, bool $eol, array $architectures, array $sections, array $sources, string $signingKey): void
    {
        $this->label = $label;
        $this->release = $release;
        $this->codename = $codename;
        $this->eol = $eol;
        $this->architectures = $architectures;
        $this->sections = $sections;
        $this->sources = $sources;
        $this->signingKey = $signingKey;
    }

    /**
     * Même forme qu'une distribution du catalogue (Sas\Bundle\Distributions), clé fournie en ASCII armor.
     *
     * @return Distribution
     */
    public function toDistribution(): array
    {
        $deb = Ecosystem::Deb === $this->ecosystem;
        $sections = [];
        foreach ($this->sections as $section) {
            $sections[$section] = ['label' => $section, 'default' => true];
        }

        return [
            'ecosystem' => $this->ecosystem,
            'label' => $this->label,
            'codename' => $deb ? $this->codename : $this->release,
            'release' => $this->release,
            'eol' => $this->eol,
            'architectures' => array_combine($this->architectures, $this->architectures),
            'sections' => $sections,
            'custom' => true,
            'modules' => false,
        ] + ($deb
            ? ['suites' => array_map(fn (array $s) => ['url' => $s['url'], 'suite' => (string) ($s['suite'] ?? ''), 'keys' => [], 'key' => $this->signingKey], $this->sources)]
            : ['repos' => array_combine(
                array_map(static fn (array $s) => (string) ($s['name'] ?? ''), $this->sources),
                array_map(fn (array $s) => ['url' => $s['url'], 'keys' => [], 'key' => $this->signingKey], $this->sources),
            )]);
    }
}
