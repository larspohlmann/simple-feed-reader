<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RunStatus;
use App\Repository\RecommendationRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One "For you" run, checkpointed after every tick so any driver resumes it where the last tick stopped. The candidate
 * batches freeze at snapshot(), so a resume retries the exact failed batch; the reading history is read fresh.
 */
#[ORM\Entity(repositoryClass: RecommendationRunRepository::class)]
#[ORM\Table(name: 'recommendation_run')]
#[ORM\Index(name: 'idx_recommendation_run_user_status', columns: ['user_id', 'status'])]
final class RecommendationRun
{
    use PersistedId;

    /** First call plus the spec's two retries. */
    public const int MAX_ATTEMPTS = 3;

    /**
     * Transport failures in a row before the run fails. MAX_ATTEMPTS counts only unusable replies, which an
     * unreachable provider never sends, so without this ceiling such a run would tick forever.
     */
    public const int MAX_TRANSPORT_FAILURES = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: RunStatus::class)]
    private RunStatus $status = RunStatus::Pending;

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

    /** @var list<list<array{id: int, score?: int, reason: string}>> */
    #[ORM\Column(type: Types::JSON)]
    private array $batchWinners = [];

    #[ORM\Embedded(class: RunBatchProgress::class, columnPrefix: false)]
    private RunBatchProgress $batchProgress;

    #[ORM\Embedded(class: RunCallAttempts::class, columnPrefix: false)]
    private RunCallAttempts $callAttempts;

    #[ORM\Embedded(class: RunProfile::class, columnPrefix: false)]
    private RunProfile $runProfile;

    /**
     * The bytes the call in flight has received, written by RecordedCall straight to the database and 0 between calls,
     * so a status poll sees progress while the tick is blocked on the provider. This entity only reads it.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $streamedChars = 0;

    #[ORM\Embedded(class: ProviderUsage::class, columnPrefix: false)]
    private ProviderUsage $providerUsage;

    #[ORM\Embedded(class: RunThrottle::class, columnPrefix: false)]
    private RunThrottle $throttle;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(User $user, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->createdAt = $createdAt;
        $this->providerUsage = new ProviderUsage();
        $this->runProfile = new RunProfile();
        $this->batchProgress = new RunBatchProgress();
        $this->throttle = new RunThrottle();
        $this->callAttempts = new RunCallAttempts();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStatus(): RunStatus
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
        $this->guardStatus(RunStatus::Pending, 'snapshot');

        $this->candidateBatches = $candidateBatches;
        $this->status = RunStatus::Running;
    }

    /**
     * @return list<list<int>>
     */
    public function getCandidateBatches(): array
    {
        return $this->candidateBatches ?? [];
    }

    public function getProgress(): RecommendationRunProgress
    {
        return RecommendationRunProgress::forBatchPlan(
            $this->candidateBatches,
            $this->batchProgress->batchesDone(),
            $this->callAttempts->attempts(),
            $this->isDistilled(),
        );
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $picks
     */
    public function recordBatchWinners(array $picks): void
    {
        $this->guardStatus(RunStatus::Running, 'recordBatchWinners');

        $this->batchWinners[] = $picks;
        $this->batchProgress->recordCompletedBatch();
        $this->callAttempts->reset();
        $this->throttle->clearDeferral();
    }

    public function markFirstBatchStarted(): void
    {
        $this->guardStatus(RunStatus::Running, 'mark the first batch as started');
        $this->batchProgress->markFirstBatchStarted();
    }

    public function hasFirstBatchStarted(): bool
    {
        return $this->batchProgress->hasFirstBatchStarted()
            || $this->batchProgress->batchesDone() > 0;
    }

    /**
     * Rows from before scores existed read as score 0, so every caller sees a scored winner; such a row sorts last.
     *
     * @return list<list<array{id: int, score: int, reason: string}>>
     */
    public function getWinners(): array
    {
        return array_map(
            static fn (array $batch): array => array_map(
                static fn (array $winner): array => [
                    'id' => $winner['id'],
                    'score' => $winner['score'] ?? 0,
                    'reason' => $winner['reason'],
                ],
                $batch,
            ),
            $this->batchWinners,
        );
    }

    public function hasExhaustedTransportRetries(): bool
    {
        return $this->callAttempts->transportFailures() >= self::MAX_TRANSPORT_FAILURES;
    }

    public function getLastInvalidReply(): ?string
    {
        return $this->callAttempts->lastInvalidReply();
    }

    /** The call attempts of a running run: an unusable reply or a transport failure is recorded through them. */
    public function getRunningCallAttempts(): RunningCallAttempts
    {
        $this->guardStatus(RunStatus::Running, 'record a call attempt on');

        return new RunningCallAttempts($this->callAttempts);
    }

    /**
     * Records the profile distilled for this run and freezes it: later reads
     * of getProfileText() see exactly what this run produced, even a null
     * from a degraded distillation, never a stale profile from a prior run.
     */
    public function recordProfile(?string $profileText): void
    {
        $this->guardStatus(RunStatus::Running, 'recordProfile');

        $this->runProfile->record($profileText);
        $this->callAttempts->reset();
        $this->throttle->clearDeferral();
    }

    public function getProfileText(): ?string
    {
        return $this->runProfile->getProfileText();
    }

    public function isDistilled(): bool
    {
        return $this->runProfile->isDistilled();
    }

    public function getAttempts(): int
    {
        return $this->callAttempts->attempts();
    }

    public function getTransportFailures(): int
    {
        return $this->callAttempts->transportFailures();
    }

    public function getStreamedChars(): int
    {
        return $this->streamedChars;
    }

    public function isRetryDeferredAt(\DateTimeImmutable $now): bool
    {
        return $this->throttle->mustWait($now);
    }

    public function getWaveConcurrencyCap(int $configuredCap): int
    {
        return $this->throttle->effectiveCap($configuredCap);
    }

    public function getRetryNotBefore(): ?\DateTimeImmutable
    {
        return $this->throttle->retryNotBefore();
    }

    /** The throttle of a running run: a rate limit defers it and narrows the next wave through it. */
    public function getRunningThrottle(): RunningThrottle
    {
        $this->guardStatus(RunStatus::Running, 'throttle');

        return new RunningThrottle($this->throttle);
    }

    public function stampProvider(?string $providerHost, ?string $model): void
    {
        $this->providerUsage->stamp($providerHost, $model);
    }

    public function getProviderHost(): ?string
    {
        return $this->providerUsage->getProviderHost();
    }

    public function getModel(): ?string
    {
        return $this->providerUsage->getModel();
    }

    public function getPromptTokens(): int
    {
        return $this->providerUsage->getPromptTokens();
    }

    public function getCompletionTokens(): int
    {
        return $this->providerUsage->getCompletionTokens();
    }

    public function getReasoningTokens(): int
    {
        return $this->providerUsage->getReasoningTokens();
    }

    public function getCachedTokens(): int
    {
        return $this->providerUsage->getCachedTokens();
    }

    public function getCostNanoCredits(): ?int
    {
        return $this->providerUsage->getCostNanoCredits();
    }

    public function complete(\DateTimeImmutable $when): void
    {
        $this->guardStatus(RunStatus::Running, 'complete');

        $this->terminate(RunStatus::Completed, $when);
        $this->batchProgress->completeAllBatches(
            $this->getProgress()->batchesTotal ?? $this->batchProgress->batchesDone(),
        );
        $this->callAttempts->resetTransportFailures();
        $this->throttle->clearDeferral();
    }

    /** Also legal from PENDING: a run whose account lost its AI configuration before the snapshot must still end. */
    public function fail(string $error, \DateTimeImmutable $when): void
    {
        $this->guardStatusOneOf(RunStatus::active(), 'fail');

        $this->terminate(RunStatus::Failed, $when);
        $this->error = $error;
    }

    /**
     * A terminal state of its own, not a failure: the user decided. Also legal from PENDING, like fail(). A call
     * already in flight still finishes; RecommendationTickCheckpoint then makes its tick discard the result.
     */
    public function cancel(\DateTimeImmutable $when): void
    {
        $this->guardStatusOneOf(RunStatus::active(), 'cancel');

        $this->terminate(RunStatus::Cancelled, $when);
    }

    public function resume(): void
    {
        $this->guardStatus(RunStatus::Failed, 'resume');

        $this->status = RunStatus::Running;
        $this->error = null;
        $this->callAttempts->reset();
        $this->throttle->reset();
    }

    /** Every ending goes through here: a run without completedAt reads as unfinished to every query. */
    private function terminate(RunStatus $status, \DateTimeImmutable $when): void
    {
        $this->status = $status;
        $this->completedAt = $when;
    }

    private function guardStatus(RunStatus $requiredStatus, string $transition): void
    {
        $this->guardStatusOneOf([$requiredStatus], $transition);
    }

    /**
     * @param list<RunStatus> $allowedStatuses
     */
    private function guardStatusOneOf(array $allowedStatuses, string $transition): void
    {
        if (!\in_array($this->status, $allowedStatuses, true)) {
            throw new \LogicException(sprintf(
                'Cannot %s a recommendation run from status "%s".',
                $transition,
                $this->status->value,
            ));
        }
    }
}
