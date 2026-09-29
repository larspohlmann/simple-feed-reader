<?php

declare(strict_types=1);

namespace App\Service\Fetch\Pass;

use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Support\HostKey;

/** In-flight requests per host, capped so a feed whose host is full waits for a slot instead of earning a 429. */
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

    public function hasCapacityFor(FetchAttemptModel $attempt): bool
    {
        return ($this->inFlightByHost[$this->keyOf($attempt)] ?? 0) < $this->capacityPerHost;
    }

    public function acquire(FetchAttemptModel $attempt): void
    {
        $host = $this->keyOf($attempt);
        $this->inFlightByHost[$host] = ($this->inFlightByHost[$host] ?? 0) + 1;
    }

    public function release(FetchAttemptModel $attempt): void
    {
        $host = $this->keyOf($attempt);
        $remaining = ($this->inFlightByHost[$host] ?? 0) - 1;

        if ($remaining <= 0) {
            unset($this->inFlightByHost[$host]);

            return;
        }

        $this->inFlightByHost[$host] = $remaining;
    }

    private function keyOf(FetchAttemptModel $attempt): string
    {
        return HostKey::forUrl($attempt->url);
    }
}
