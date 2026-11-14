<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Entity\Feed;
use Symfony\Component\Clock\ClockInterface;

/**
 * Starts a pass's due feeds, whatever their kind, in the order the pass was given while the time budget allows. The
 * engine pulls one per free slot, so the deadline is checked when a fetch would start, and a wave that finishes early
 * buys the next feed its chance.
 */
final class BudgetedFeedQueue
{
    private const int SAFETY_MARGIN_SECONDS = 10;

    /** @var list<int> */
    private array $startedFeedIds = [];

    /** @param list<Feed> $feeds */
    public function __construct(
        private readonly array $feeds,
        private readonly ClockInterface $clock,
        private readonly int $deadline,
    ) {
    }

    /** @return \Generator<int, Feed, mixed, void> every kind of feed, in the order the pass was given */
    public function startedFeeds(): \Generator
    {
        foreach ($this->feeds as $feed) {
            if (!$this->mayStartAnother()) {
                return;
            }

            $this->startedFeedIds[] = $feed->requireId();

            yield $feed->requireId() => $feed;
        }
    }

    /** @return list<int> */
    public function startedFeedIds(): array
    {
        return $this->startedFeedIds;
    }

    public function skippedCount(): int
    {
        return \count($this->feeds) - \count($this->startedFeedIds);
    }

    /**
     * The first feed always starts: the user endpoint polls until `remaining` reaches 0, so a run that starts nothing
     * would spin the client forever. One feed per call is slow; zero never terminates.
     */
    private function mayStartAnother(): bool
    {
        if ([] === $this->startedFeedIds) {
            return true;
        }

        return $this->deadline - $this->clock->now()->getTimestamp() >= self::SAFETY_MARGIN_SECONDS;
    }
}
