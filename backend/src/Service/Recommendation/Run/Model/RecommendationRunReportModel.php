<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Entity\RecommendationRun;

/**
 * The poll-facing view of a run, without its checkpoint internals. `status` also takes the two values below, which
 * the database never holds.
 */
final readonly class RecommendationRunReportModel
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
        public RunStartModel $start = new RunStartModel(),
        public ?RunPlanModel $plan = null,
    ) {
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
        $progress = $run->getProgress();
        $batchCount = $progress->batchCount;

        return new self(
            $run->getStatus()->value,
            $progress->batchesTotal,
            $progress->batchesDone,
            $run->getError(),
            streamedChars: $run->getStreamedChars(),
            start: new RunStartModel($run->getCreatedAt(), $run->hasFirstBatchStarted()),
            plan: null === $batchCount ? null : new RunPlanModel($run->getEngineKind(), $batchCount),
        );
    }

    /** A pure status read: somebody else drives the run, so this request advanced nothing. */
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
            start: $this->start,
            plan: $this->plan,
        );
    }

    /**
     * The per-user lock is held while no driver heartbeat is fresh. A busy report is background either way, so only
     * this flag tells "a worker owns this" from "this may be stuck".
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
            start: $this->start,
            plan: $this->plan,
        );
    }
}
