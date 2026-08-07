<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RecommendationRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A poll-driven tick advances this run one provider call at a time and
 * checkpoints its progress here, so a crashed or restarted worker can resume
 * exactly where it left off instead of re-running the whole selection.
 *
 * The candidate pool is frozen at snapshot time so that a resumed run retries
 * the exact failed batch (#308); history is deliberately NOT frozen — it only
 * shades the prompt.
 */
#[ORM\Entity(repositoryClass: RecommendationRunRepository::class)]
#[ORM\Table(name: 'recommendation_run')]
#[ORM\Index(name: 'idx_recommendation_run_user_status', columns: ['user_id', 'status'])]
class RecommendationRun
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_FAILED = 'failed';

    /** First call plus the spec's two retries. */
    public const int MAX_ATTEMPTS = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    /**
     * @var list<list<int>>|null null while pending; frozen by snapshot()
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $candidateBatches = null;

    /**
     * @var list<list<array{id: int, reason: string}>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $batchWinners = [];

    #[ORM\Column(options: ['default' => 0])]
    private int $batchesDone = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastInvalidReply = null;

    public function __construct(User $user, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    /**
     * @param list<list<int>> $candidateBatches
     */
    public function snapshot(array $candidateBatches): void
    {
        $this->guardStatus(self::STATUS_PENDING, 'snapshot');

        $this->candidateBatches = $candidateBatches;
        $this->status = self::STATUS_RUNNING;
    }

    /**
     * @return list<list<int>>
     */
    public function getCandidateBatches(): array
    {
        return $this->candidateBatches ?? [];
    }

    public function progress(): RecommendationRunProgress
    {
        return RecommendationRunProgress::forBatchPlan($this->candidateBatches, $this->batchesDone);
    }

    /**
     * @param list<array{id: int, reason: string}> $picks
     */
    public function recordBatchWinners(array $picks): void
    {
        $this->guardStatus(self::STATUS_RUNNING, 'recordBatchWinners');

        $this->batchWinners[] = $picks;
        $this->batchesDone++;
        $this->attempts = 0;
        $this->lastInvalidReply = null;
    }

    /**
     * @return list<list<array{id: int, reason: string}>>
     */
    public function getWinners(): array
    {
        return $this->batchWinners;
    }

    public function recordInvalidReply(string $reply): void
    {
        $this->guardStatus(self::STATUS_RUNNING, 'recordInvalidReply');

        $this->attempts++;
        $this->lastInvalidReply = $reply;
    }

    public function attemptsExhausted(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    public function getLastInvalidReply(): ?string
    {
        return $this->lastInvalidReply;
    }

    public function complete(\DateTimeImmutable $when): void
    {
        $this->guardStatus(self::STATUS_RUNNING, 'complete');

        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = $when;
        $this->batchesDone = $this->progress()->batchesTotal ?? $this->batchesDone;
    }

    public function fail(string $error, \DateTimeImmutable $when): void
    {
        $this->guardStatus(self::STATUS_RUNNING, 'fail');

        $this->status = self::STATUS_FAILED;
        $this->error = $error;
        $this->completedAt = $when;
    }

    public function resume(): void
    {
        $this->guardStatus(self::STATUS_FAILED, 'resume');

        $this->status = self::STATUS_RUNNING;
        $this->error = null;
        $this->attempts = 0;
        $this->lastInvalidReply = null;
    }

    private function guardStatus(string $requiredStatus, string $transition): void
    {
        if ($this->status !== $requiredStatus) {
            throw new \LogicException(sprintf(
                'Cannot %s a recommendation run from status "%s".',
                $transition,
                $this->status,
            ));
        }
    }
}
