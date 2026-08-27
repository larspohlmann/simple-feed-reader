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
 *
 * The public surface sits over PHPMD's ten-method ceiling, which the
 * suppression below accepts: every state transition of the run is its own
 * named method (snapshot, recordBatchWinners, recordInvalidReply,
 * recordTransportFailure, recordProfile, complete, fail, cancel, resume), and
 * beside them stand the queries that read the checkpoint back and
 * stampProvider(), which RecommendationRunStarter calls at start and again at
 * resume (#409). None of them is a duplicate a merge could remove, and none
 * can be renamed to match the rule's get/set ignore pattern without lying
 * about what it does. The seven usage columns are already off this class as
 * a ProviderUsage embeddable, and profileText/distilled are a RunProfile
 * embeddable for the same reason (#493) — both are the fix the field-count
 * half of this same finding was pointing at.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
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

    /** Terminal, and reached only by the user stopping the run themselves. */
    public const string STATUS_CANCELLED = 'cancelled';

    /**
     * The statuses that mean the run is over. resume() deliberately leaves
     * completedAt standing, so "carries a completion time" and "has finished"
     * are two different questions: anything that reports a run as finished has
     * to ask this one (#409).
     *
     * @var list<string>
     */
    public const array TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** First call plus the spec's two retries. */
    public const int MAX_ATTEMPTS = 3;

    /**
     * Ceiling on consecutive provider *transport* failures -- separate from
     * MAX_ATTEMPTS, which counts unusable replies. A provider that is simply
     * unreachable never produces a reply to be unusable, so without this a
     * run wedged behind a broken provider would tick forever (#308 final
     * review, Important 2).
     */
    public const int MAX_TRANSPORT_FAILURES = 3;

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
     * @var list<list<array{id: int, score?: int, reason: string}>>
     *     `score` is optional only for rows written before scores existed
     *     (a run in flight across the deploy); the ranker reads those as 0
     */
    #[ORM\Column(type: Types::JSON)]
    private array $batchWinners = [];

    /** The mutable checkpoints specific to the batch phase. Keeping them
     * together separates batch state from the run's lifecycle state. */
    #[ORM\Embedded(class: RunBatchProgress::class, columnPrefix: false)]
    private RunBatchProgress $batchProgress;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $transportFailures = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastInvalidReply = null;

    #[ORM\Embedded(class: RunProfile::class, columnPrefix: false)]
    private RunProfile $runProfile;

    /**
     * Raw SSE bytes received so far by the provider call currently in
     * flight, checkpointed every ~2 s by RecordedCall via direct DBAL
     * updates and reset to 0 when the call ends. Deliberately written
     * outside the EntityManager — this entity only ever reads it — so the
     * value is visible to the cheap status poll while the tick request is
     * still blocked on the provider. Debug-independent: this is the
     * progress indicator's liveness signal (#309), not debug data.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $streamedChars = 0;

    #[ORM\Embedded(class: ProviderUsage::class, columnPrefix: false)]
    private ProviderUsage $providerUsage;

    public function __construct(User $user, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->createdAt = $createdAt;
        $this->providerUsage = new ProviderUsage();
        $this->runProfile = new RunProfile();
        $this->batchProgress = new RunBatchProgress();
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
        return RecommendationRunProgress::forBatchPlan(
            $this->candidateBatches,
            $this->batchProgress->batchesDone(),
            $this->attempts,
            $this->isDistilled(),
        );
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $picks
     */
    public function recordBatchWinners(array $picks): void
    {
        $this->guardStatus(self::STATUS_RUNNING, 'recordBatchWinners');

        $this->batchWinners[] = $picks;
        $this->batchProgress->recordCompletedBatch();
        $this->attempts = 0;
        $this->transportFailures = 0;
        $this->lastInvalidReply = null;
    }

    /** Mark this run once its first scored batch starts, before the provider
     * call begins so a concurrent status poll cannot start the ETA early. */
    public function markFirstBatchStarted(): void
    {
        $this->guardStatus(self::STATUS_RUNNING, 'mark the first batch as started');
        $this->batchProgress->markFirstBatchStarted();
    }

    public function hasFirstBatchStarted(): bool
    {
        return $this->batchProgress->hasFirstBatchStarted()
            || $this->batchProgress->batchesDone() > 0;
    }

    /**
     * Defaults the score for rows written before scores existed (a run in
     * flight across the deploy) so the concession stays at the column, not in
     * every consumer: callers always see a scored winner. Such a row sorts
     * last, the run still completes, and the next run self-heals.
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

    public function recordInvalidReply(string $reply): void
    {
        $this->guardStatus(self::STATUS_RUNNING, 'recordInvalidReply');

        $this->attempts++;
        $this->lastInvalidReply = $reply;
    }

    /**
     * Records one provider call that never produced a reply -- a transport
     * failure, not an unusable one. Kept as its own counter, reset
     * independently of `attempts`, so the "unusable reply" retry semantics
     * are unaffected by an unrelated network blip.
     *
     * @return bool true once this failure has reached MAX_TRANSPORT_FAILURES
     */
    public function recordTransportFailure(): bool
    {
        $this->guardStatus(self::STATUS_RUNNING, 'recordTransportFailure');

        $this->transportFailures++;

        return $this->transportFailures >= self::MAX_TRANSPORT_FAILURES;
    }

    public function getLastInvalidReply(): ?string
    {
        return $this->lastInvalidReply;
    }

    /**
     * Records the profile distilled for this run and freezes it: later reads
     * of getProfileText() see exactly what this run produced, even a null
     * from a degraded distillation, never a stale profile from a prior run.
     */
    public function recordProfile(?string $profileText): void
    {
        $this->guardStatus(self::STATUS_RUNNING, 'recordProfile');

        $this->runProfile->record($profileText);
        $this->attempts = 0;
        $this->transportFailures = 0;
        $this->lastInvalidReply = null;
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
        return $this->attempts;
    }

    public function getTransportFailures(): int
    {
        return $this->transportFailures;
    }

    public function getStreamedChars(): int
    {
        return $this->streamedChars;
    }

    /**
     * Records which provider and model this run is about to use. Called at
     * start and again at resume, so a run resumed after the account switched
     * models is stamped with the model it will actually call.
     */
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
        $this->guardStatus(self::STATUS_RUNNING, 'complete');

        $this->terminate(self::STATUS_COMPLETED, $when);
        $this->batchProgress->completeAllBatches(
            $this->progress()->batchesTotal ?? $this->batchProgress->batchesDone(),
        );
        $this->transportFailures = 0;
    }

    /**
     * Failing is reachable from PENDING as well as RUNNING: a run whose
     * account loses its AI configuration before its very first snapshot
     * never reaches RUNNING at all, and that must still end in a terminal
     * state rather than being stuck retried forever (#311).
     */
    public function fail(string $error, \DateTimeImmutable $when): void
    {
        $this->guardStatusOneOf([self::STATUS_PENDING, self::STATUS_RUNNING], 'fail');

        $this->terminate(self::STATUS_FAILED, $when);
        $this->error = $error;
    }

    /**
     * Stopping is the user's own decision, so it is a terminal state of its
     * own rather than a failure: nothing went wrong, and the debug surfaces
     * must not read as though something did. Reachable from PENDING as well
     * as RUNNING for the same reason fail() is — a run stopped before its
     * first snapshot must still end somewhere terminal.
     *
     * A tick already inside a provider call cannot be interrupted, so this
     * transition is only half the cancellation: RecommendationRunAdvancer
     * re-reads the status after each call and throws its result away rather
     * than flushing it over this one.
     */
    public function cancel(\DateTimeImmutable $when): void
    {
        $this->guardStatusOneOf([self::STATUS_PENDING, self::STATUS_RUNNING], 'cancel');

        $this->terminate(self::STATUS_CANCELLED, $when);
    }

    public function resume(): void
    {
        $this->guardStatus(self::STATUS_FAILED, 'resume');

        $this->status = self::STATUS_RUNNING;
        $this->error = null;
        $this->attempts = 0;
        $this->transportFailures = 0;
        $this->lastInvalidReply = null;
    }

    /**
     * What every ending has in common. Extracted at the third one: completing,
     * failing and cancelling had each grown their own copy of "set the status,
     * stamp the time", and a fourth ending that forgot the stamp would leave a
     * finished run looking unfinished to every query that reads completedAt.
     */
    private function terminate(string $status, \DateTimeImmutable $when): void
    {
        $this->status = $status;
        $this->completedAt = $when;
    }

    private function guardStatus(string $requiredStatus, string $transition): void
    {
        $this->guardStatusOneOf([$requiredStatus], $transition);
    }

    /**
     * @param list<string> $allowedStatuses
     */
    private function guardStatusOneOf(array $allowedStatuses, string $transition): void
    {
        if (!\in_array($this->status, $allowedStatuses, true)) {
            throw new \LogicException(sprintf(
                'Cannot %s a recommendation run from status "%s".',
                $transition,
                $this->status,
            ));
        }
    }
}
