<?php

declare(strict_types=1);

namespace App\Service\Refresh;

final readonly class RefreshReport
{
    public const string STATUS_ABORTED = 'aborted';

    private function __construct(
        public string $status,
        public int $total,
        public int $fetched,
        public int $notModified,
        public int $failed,
        /** Feeds the site rationed. Not failures: they are healthy and will be asked again shortly. */
        public int $throttled,
        public int $skippedForBudget,
        public int $remaining,
        public int $pruned,
    ) {
    }

    public static function busy(): self
    {
        return new self('busy', 0, 0, 0, 0, 0, 0, 0, 0);
    }

    public static function finished(
        int $total,
        int $fetched,
        int $notModified,
        int $failed,
        int $throttled,
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
            $throttled,
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
        int $throttled,
        int $remaining,
    ): self {
        return new self(self::STATUS_ABORTED, $total, $fetched, $notModified, $failed, $throttled, 0, $remaining, 0);
    }

    /**
     * Whether persistence failed and the shared EntityManager is closed.
     * A caller that shares the EntityManager with other work this same
     * request — MaintenanceTick does, with the recommendation sweep — must
     * check this before touching it again.
     */
    public function isAborted(): bool
    {
        return self::STATUS_ABORTED === $this->status;
    }

    /**
     * @return array{status: string, total: int, fetched: int, notModified: int,
     *     failed: int, throttled: int, skippedForBudget: int, remaining: int, pruned: int}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'total' => $this->total,
            'fetched' => $this->fetched,
            'notModified' => $this->notModified,
            'failed' => $this->failed,
            'throttled' => $this->throttled,
            'skippedForBudget' => $this->skippedForBudget,
            'remaining' => $this->remaining,
            'pruned' => $this->pruned,
        ];
    }
}
