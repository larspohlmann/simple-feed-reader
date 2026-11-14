<?php

declare(strict_types=1);

namespace App\Service\Fetch\Pass;

use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\RequestSlot\RequestSlotModelInterface;

/**
 * In-flight requests per slot, counted under the slot's class and its key, so two kinds of slot never share a cap. A
 * ticket goes out only while every slot it names has room, so it waits instead of earning a 429, and it holds all of
 * them until it retires.
 */
final class RequestSlots
{
    /** @var array<string, int> */
    private array $inFlightBySlot = [];

    public function hasCapacityFor(FetchAttemptModel $attempt): bool
    {
        foreach ($attempt->ticket->slots as $slot) {
            if ($this->inFlightIn(self::keyOf($slot, $attempt->url)) >= $slot->capacity()) {
                return false;
            }
        }

        return true;
    }

    public function acquire(FetchAttemptModel $attempt): void
    {
        foreach ($this->keysOf($attempt) as $key) {
            $this->inFlightBySlot[$key] = $this->inFlightIn($key) + 1;
        }
    }

    public function release(FetchAttemptModel $attempt): void
    {
        foreach ($this->keysOf($attempt) as $key) {
            $this->inFlightBySlot[$key] = max(0, $this->inFlightIn($key) - 1);
        }
    }

    private function inFlightIn(string $key): int
    {
        return $this->inFlightBySlot[$key] ?? 0;
    }

    /** @return list<string> */
    private function keysOf(FetchAttemptModel $attempt): array
    {
        $keys = [];
        foreach ($attempt->ticket->slots as $slot) {
            $keys[] = self::keyOf($slot, $attempt->url);
        }

        return $keys;
    }

    private static function keyOf(RequestSlotModelInterface $slot, string $url): string
    {
        return $slot::class . "\0" . $slot->keyFor($url);
    }
}
