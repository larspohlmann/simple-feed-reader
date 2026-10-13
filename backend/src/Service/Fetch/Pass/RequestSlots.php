<?php

declare(strict_types=1);

namespace App\Service\Fetch\Pass;

use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Support\CapacityGuard;

/** In-flight requests per slot key, capped so a ticket whose slot is full waits for it instead of earning a 429. */
final class RequestSlots
{
    /** @var array<string, int> */
    private array $inFlightBySlot = [];

    public function __construct(private readonly int $capacityPerSlot)
    {
        CapacityGuard::atLeastOne($capacityPerSlot, 'Slot capacity');
    }

    public function hasCapacityFor(FetchAttemptModel $attempt): bool
    {
        return $this->inFlightIn($this->keyOf($attempt)) < $this->capacityPerSlot;
    }

    public function acquire(FetchAttemptModel $attempt): void
    {
        $slot = $this->keyOf($attempt);
        $this->inFlightBySlot[$slot] = $this->inFlightIn($slot) + 1;
    }

    public function release(FetchAttemptModel $attempt): void
    {
        $slot = $this->keyOf($attempt);
        $this->inFlightBySlot[$slot] = max(0, $this->inFlightIn($slot) - 1);
    }

    private function inFlightIn(string $slot): int
    {
        return $this->inFlightBySlot[$slot] ?? 0;
    }

    private function keyOf(FetchAttemptModel $attempt): string
    {
        return $attempt->ticket->slotKeyFor($attempt->url);
    }
}
