<?php

namespace App\Entity;

use App\Repository\JobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Tâche de fond (import d'un bundle) suivie depuis l'interface. */
#[ORM\Entity(repositoryClass: JobRepository::class)]
#[ORM\Index(columns: ['status'])]
class Job
{
    public const string QUEUED = 'queued';
    public const string RUNNING = 'running';
    public const string SUCCEEDED = 'succeeded';
    public const string FAILED = 'failed';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $type;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 16)]
    private string $status = self::QUEUED;

    /** 0 à 100, ou null si la progression est inconnue */
    #[ORM\Column(nullable: true)]
    private ?int $progress = null;

    #[ORM\Column(length: 255)]
    private string $step = 'En attente';

    #[ORM\Column(type: Types::TEXT)]
    private string $log = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    /** @var array<string, mixed> paramètres de la tâche (bundle à importer…) */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** @param array<string, mixed> $payload */
    public function __construct(string $type, string $title, array $payload = [])
    {
        $this->type = $type;
        $this->title = $title;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return \in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    public function getProgress(): ?int
    {
        return $this->progress;
    }

    public function getStep(): string
    {
        return $this->step;
    }

    public function getLog(): string
    {
        return $this->log;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function start(): void
    {
        $this->status = self::RUNNING;
        $this->startedAt = new \DateTimeImmutable();
        $this->step = 'Démarrage';
    }

    public function report(string $step, ?int $progress = null): void
    {
        $this->step = mb_substr($step, 0, 255);
        $this->progress = null === $progress ? null : max(0, min(100, $progress));
    }

    public function appendLog(string $line): void
    {
        $this->log .= sprintf("[%s] %s\n", date('H:i:s'), $line);
        // Journal borné : on garde la fin
        if (\strlen($this->log) > 200_000) {
            $this->log = "…\n".substr($this->log, -150_000);
        }
    }

    public function succeed(string $step): void
    {
        $this->status = self::SUCCEEDED;
        $this->step = $step;
        $this->progress = 100;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function fail(string $error): void
    {
        $this->status = self::FAILED;
        $this->error = $error;
        $this->step = 'Échec';
        $this->finishedAt = new \DateTimeImmutable();
    }
}
