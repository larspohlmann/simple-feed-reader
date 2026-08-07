<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;

/**
 * The poll-facing view of a recommendation run: enough for a client or the
 * #311 worker to decide what to do next, without exposing the entity's
 * checkpoint internals (candidate batches, batch winners, retry state).
 *
 * `status` widens the entity's four persisted statuses with two that never
 * reach the database: `none` (no run has ever started) and `busy` (another
 * tick currently holds the per-user lock).
 */
final readonly class RecommendationRunReport
{
    private function __construct(
        public string $status,
        public ?int $batchesTotal,
        public int $batchesDone,
        public ?string $error,
        public bool $background = false,
    ) {
    }

    public static function none(): self
    {
        return new self('none', null, 0, null);
    }

    public static function busy(): self
    {
        return new self('busy', null, 0, null);
    }

    public static function fromRun(RecommendationRun $run): self
    {
        $progress = $run->progress();

        return new self($run->getStatus(), $progress->batchesTotal, $progress->batchesDone, $run->getError());
    }

    /**
     * The #311 poll driver's marker that a fresh worker heartbeat made this
     * report a pure status read rather than a tick that just ran.
     */
    public function inBackground(): self
    {
        return new self($this->status, $this->batchesTotal, $this->batchesDone, $this->error, true);
    }

    /**
     * @return array{status: string, batchesTotal: ?int, batchesDone: int, error: ?string, background: bool}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'batchesTotal' => $this->batchesTotal,
            'batchesDone' => $this->batchesDone,
            'error' => $this->error,
            'background' => $this->background,
        ];
    }
}
