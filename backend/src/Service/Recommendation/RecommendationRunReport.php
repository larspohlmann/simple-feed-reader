<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;

/**
 * The poll-facing view of a recommendation run: enough for a client or the
 * #311 worker to decide what to do next, without exposing the entity's
 * checkpoint internals (candidate batches, batch winners, retry state).
 *
 * `status` widens the entity's five persisted statuses with two that never
 * reach the database: `none` (no run has ever started) and `busy` (another
 * tick currently holds the per-user lock).
 */
final readonly class RecommendationRunReport
{
    /** No run has ever started for this account. */
    public const string STATUS_NONE = 'none';

    /** Another tick holds the per-user lock; this one did no work. */
    public const string STATUS_BUSY = 'busy';

    private function __construct(
        public string $status,
        public ?int $batchesTotal,
        public int $batchesDone,
        public ?string $error,
        public bool $background = false,
        public bool $waitingForLock = false,
        public int $streamedChars = 0,
        public ?\DateTimeImmutable $startedAt = null,
        public bool $firstBatchStarted = false,
    ) {
    }

    /**
     * Seconds since the run started, on the caller's clock. Null before the
     * run has a start instant (the `none`/`busy` reports). Computed here so
     * both the status payload's `elapsedSeconds` and the ETA estimate read the
     * one definition rather than each subtracting timestamps its own way.
     */
    public function elapsedSecondsAt(\DateTimeImmutable $now): ?int
    {
        if (null === $this->startedAt) {
            return null;
        }

        return max(0, $now->getTimestamp() - $this->startedAt->getTimestamp());
    }

    public static function none(): self
    {
        return new self(self::STATUS_NONE, null, 0, null);
    }

    public static function busy(): self
    {
        return new self(self::STATUS_BUSY, null, 0, null);
    }

    public static function fromRun(RecommendationRun $run): self
    {
        $progress = $run->progress();

        return new self(
            $run->getStatus(),
            $progress->batchesTotal,
            $progress->batchesDone,
            $run->getError(),
            streamedChars: $run->getStreamedChars(),
            startedAt: $run->getCreatedAt(),
            firstBatchStarted: $run->hasFirstBatchStarted(),
        );
    }

    /**
     * The #311 poll driver's marker that a fresh worker heartbeat made this
     * report a pure status read rather than a tick that just ran.
     */
    public function inBackground(): self
    {
        return new self(
            $this->status,
            $this->batchesTotal,
            $this->batchesDone,
            $this->error,
            background: true,
            waitingForLock: $this->waitingForLock,
            streamedChars: $this->streamedChars,
            startedAt: $this->startedAt,
            firstBatchStarted: $this->firstBatchStarted,
        );
    }

    /**
     * The #439 marker that the per-user lock is held with no fresh heartbeat:
     * the poll driver sets it only on a `busy` advance() whose presence read
     * came back "nobody driving" (a live holder's lock would have read fresh).
     * A busy report is stamped background either way, so `inBackground()` alone
     * cannot distinguish "a worker owns this" from "this may be stuck".
     */
    public function waitingForLock(): self
    {
        return new self(
            $this->status,
            $this->batchesTotal,
            $this->batchesDone,
            $this->error,
            background: $this->background,
            waitingForLock: true,
            streamedChars: $this->streamedChars,
            startedAt: $this->startedAt,
            firstBatchStarted: $this->firstBatchStarted,
        );
    }

    /**
     * @return array{status: string, batchesTotal: ?int, batchesDone: int, error: ?string, background: bool,
     *     waitingForLock: bool, streamedChars: int, firstBatchStarted: bool}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'batchesTotal' => $this->batchesTotal,
            'batchesDone' => $this->batchesDone,
            'error' => $this->error,
            'background' => $this->background,
            'waitingForLock' => $this->waitingForLock,
            'streamedChars' => $this->streamedChars,
            'firstBatchStarted' => $this->firstBatchStarted,
        ];
    }
}
