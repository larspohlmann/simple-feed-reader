<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * How many requests to each host the sweep currently has in flight, capped so a
 * host concentration cannot become a burst. The engine acquires a slot when a
 * request goes on the wire and releases it when the response retires; a feed
 * that finds its host full waits for a slot rather than earning a 429.
 */
final class HostSlots
{
    /** @var array<string, int> */
    private array $inFlightByHost = [];

    public function __construct(private readonly int $capacityPerHost)
    {
        if ($capacityPerHost < 1) {
            throw new \InvalidArgumentException(
                sprintf('Per-host concurrency must be at least 1, got %d.', $capacityPerHost),
            );
        }
    }

    public function hasCapacityFor(FetchAttempt $attempt): bool
    {
        return ($this->inFlightByHost[$this->keyOf($attempt)] ?? 0) < $this->capacityPerHost;
    }

    public function acquire(FetchAttempt $attempt): void
    {
        $host = $this->keyOf($attempt);
        $this->inFlightByHost[$host] = ($this->inFlightByHost[$host] ?? 0) + 1;
    }

    public function release(FetchAttempt $attempt): void
    {
        $host = $this->keyOf($attempt);
        $remaining = ($this->inFlightByHost[$host] ?? 0) - 1;

        if ($remaining <= 0) {
            unset($this->inFlightByHost[$host]);

            return;
        }

        $this->inFlightByHost[$host] = $remaining;
    }

    private function keyOf(FetchAttempt $attempt): string
    {
        return HostKey::forUrl($attempt->url);
    }
}
