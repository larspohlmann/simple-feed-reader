<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Pass;

use App\Service\Outbound\Fetch\Model\FetchAttemptModel;
use App\Service\Outbound\Fetch\Model\RequestSlot\RequestSlotModelInterface;

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
            if ($this->readInFlight(self::buildKey($slot, $attempt->url)) >= $slot->capacity()) {
                return false;
            }
        }

        return true;
    }

    public function acquire(FetchAttemptModel $attempt): void
    {
        foreach ($this->buildKeys($attempt) as $key) {
            $this->inFlightBySlot[$key] = $this->readInFlight($key) + 1;
        }
    }

    public function release(FetchAttemptModel $attempt): void
    {
        foreach ($this->buildKeys($attempt) as $key) {
            $this->inFlightBySlot[$key] = max(0, $this->readInFlight($key) - 1);
        }
    }

    private function readInFlight(string $key): int
    {
        return $this->inFlightBySlot[$key] ?? 0;
    }

    /** @return list<string> */
    private function buildKeys(FetchAttemptModel $attempt): array
    {
        $keys = [];
        foreach ($attempt->ticket->slots as $slot) {
            $keys[] = self::buildKey($slot, $attempt->url);
        }

        return $keys;
    }

    private static function buildKey(RequestSlotModelInterface $slot, string $url): string
    {
        return $slot::class . "\0" . $slot->buildKey($url);
    }
}
