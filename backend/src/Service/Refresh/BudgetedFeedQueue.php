<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Service\Fetch\FetchTicket;
use Symfony\Component\Clock\ClockInterface;

/**
 * Feeds the fetch engine tickets while the time budget allows, and remembers how
 * many it let through.
 *
 * The engine pulls lazily, one ticket per free slot, so the deadline is checked
 * at the moment a fetch would start rather than up front — a wave that finishes
 * early therefore buys the next feed its chance.
 */
final class BudgetedFeedQueue
{
    private const int SAFETY_MARGIN_SECONDS = 10;

    private int $started = 0;

    /** @param list<Feed> $feeds */
    public function __construct(
        private readonly array $feeds,
        private readonly ClockInterface $clock,
        private readonly int $deadline,
    ) {
    }

    /** @return \Generator<int, FetchTicket, mixed, void> */
    public function tickets(): \Generator
    {
        foreach ($this->feeds as $feed) {
            if (!$this->mayStartAnother()) {
                return;
            }

            $this->started++;

            yield (int) $feed->getId() => new FetchTicket(
                $feed->getUrl(),
                $feed->getEtag(),
                $feed->getLastModified(),
            );
        }
    }

    public function startedCount(): int
    {
        return $this->started;
    }

    public function skippedCount(): int
    {
        return \count($this->feeds) - $this->started;
    }

    /**
     * The first feed is always started. A run that returns without touching
     * anything leaves `remaining` unchanged, and the user endpoint polls until
     * `remaining` hits 0 — so a budget at or below the safety margin would spin
     * the client forever. One feed per call is slow; zero never terminates.
     */
    private function mayStartAnother(): bool
    {
        if (0 === $this->started) {
            return true;
        }

        return $this->deadline - $this->clock->now()->getTimestamp() >= self::SAFETY_MARGIN_SECONDS;
    }
}
