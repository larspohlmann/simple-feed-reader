<?php

declare(strict_types=1);

namespace App\Service\Refresh;

final readonly class RefreshReport
{
    private function __construct(
        public string $status,
        public int $total,
        public int $fetched,
        public int $notModified,
        public int $failed,
        public int $skippedForBudget,
        public int $remaining,
        public int $pruned,
    ) {
    }

    public static function busy(): self
    {
        return new self('busy', 0, 0, 0, 0, 0, 0, 0);
    }

    public static function finished(
        int $total,
        int $fetched,
        int $notModified,
        int $failed,
        int $skippedForBudget,
        int $remaining,
        int $pruned,
    ): self {
        return new self(
            $remaining > 0 ? 'partial' : 'completed',
            $total,
            $fetched,
            $notModified,
            $failed,
            $skippedForBudget,
            $remaining,
            $pruned,
        );
    }

    /**
     * The run stopped early because persistence failed and the EntityManager
     * can no longer be trusted. $remaining is a lower bound derived from the
     * batch (the failing feed plus the ones never attempted) — it cannot be
     * queried, because querying needs the same broken EntityManager.
     */
    public static function aborted(
        int $total,
        int $fetched,
        int $notModified,
        int $failed,
        int $remaining,
    ): self {
        return new self('aborted', $total, $fetched, $notModified, $failed, 0, $remaining, 0);
    }

    /**
     * @return array{status: string, total: int, fetched: int, notModified: int,
     *     failed: int, skippedForBudget: int, remaining: int, pruned: int}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'total' => $this->total,
            'fetched' => $this->fetched,
            'notModified' => $this->notModified,
            'failed' => $this->failed,
            'skippedForBudget' => $this->skippedForBudget,
            'remaining' => $this->remaining,
            'pruned' => $this->pruned,
        ];
    }
}
