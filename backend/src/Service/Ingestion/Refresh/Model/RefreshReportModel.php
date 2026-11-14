<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model;

final readonly class RefreshReportModel
{
    public const string STATUS_ABORTED = 'aborted';

    /** The global refresh lock was held: NO slice ran, and every counter is zero. */
    public const string STATUS_BUSY = 'busy';

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
        return new self(self::STATUS_BUSY, 0, 0, 0, 0, 0, 0, 0, 0);
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
     * Persistence failed and the EntityManager can no longer be trusted. $remaining is a lower bound from the batch
     * (the failing feed plus the ones never attempted), since querying would need that same EntityManager.
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
     * Whether persistence failed and the shared EntityManager is closed. A caller sharing it with other work in the
     * same request (MaintenanceTick, with the recommendation sweep) checks this before touching it again.
     */
    public function isAborted(): bool
    {
        return self::STATUS_ABORTED === $this->status;
    }

    /**
     * @return array{status: string, total: int, fetched: int, notModified: int,
     *     failed: int, throttled: int, skippedForBudget: int, remaining: int, pruned: int}
     */
    public function toLogContext(): array
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
