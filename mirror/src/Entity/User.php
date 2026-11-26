<?php

namespace App\Entity;

use App\Repository\UserRepository;
use App\Security\Role;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, BackupCodeInterface
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $username;

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 16, enumType: Role::class, options: ['default' => 'admin'])]
    private Role $role;

    /** Secret TOTP (base32) ; null = double authentification désactivée */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $totpSecret = null;

    /** @var list<string> empreintes SHA-256 des codes de secours non utilisés */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $backupCodes = [];

    /** @var list<string> visites guidées déjà vues (identifiants des pages) */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $seenTours = [];

    public function __construct(string $username, Role $role = Role::Admin)
    {
        $this->id = Uuid::v7();
        $this->username = $username;
        $this->role = $role;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [$this->role->securityRole()];
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function setRole(Role $role): void
    {
        $this->role = $role;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hash): void
    {
        $this->password = $hash;
    }

    public function eraseCredentials(): void
    {
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return null !== $this->totpSecret;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->username;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        return null === $this->totpSecret ? null : new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    /**
     * Active la double authentification et remplace les codes de secours.
     *
     * @param list<string> $backupCodes codes en clair, conservés seulement sous forme d'empreinte
     */
    public function enableTotp(string $secret, array $backupCodes): void
    {
        $this->totpSecret = $secret;
        $this->backupCodes = array_map(self::hashCode(...), $backupCodes);
    }

    /** @return list<string> */
    public function getSeenTours(): array
    {
        return $this->seenTours;
    }

    /** Visite guidée vue (identifiant validé par l'appelant) ; liste bornée. */
    public function markTourSeen(string $tour): void
    {
        if (!\in_array($tour, $this->seenTours, true)) {
            $this->seenTours = \array_slice([...$this->seenTours, $tour], -200);
        }
    }

    /** Toutes les visites guidées réapparaîtront (Mon compte). */
    public function resetTours(): void
    {
        $this->seenTours = [];
    }

    public function disableTotp(): void
    {
        $this->totpSecret = null;
        $this->backupCodes = [];
    }

    public function getBackupCodeCount(): int
    {
        return \count($this->backupCodes);
    }

    public function isBackupCode(string $code): bool
    {
        $hash = self::hashCode($code);
        foreach ($this->backupCodes as $stored) {
            if (hash_equals($stored, $hash)) {
                return true;
            }
        }

        return false;
    }

    public function invalidateBackupCode(string $code): void
    {
        $hash = self::hashCode($code);
        $this->backupCodes = array_values(array_filter($this->backupCodes, static fn (string $stored) => !hash_equals($stored, $hash)));
    }

    /** Codes de secours saisis avec ou sans tiret, en majuscules ou minuscules */
    private static function hashCode(string $code): string
    {
        return hash('sha256', strtoupper(str_replace(['-', ' '], '', $code)));
    }
}
