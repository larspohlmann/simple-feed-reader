<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Fetch\Model\FetchTicketModel;
use Symfony\Component\Clock\ClockInterface;

/**
 * Hands the fetch engine tickets while the time budget allows. The engine pulls one per free slot, so the deadline is
 * checked when a fetch would start, and a wave that finishes early buys the next feed its chance.
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

    /** @return \Generator<int, FetchTicketModel, mixed, void> */
    public function tickets(): \Generator
    {
        $isFetchedByUrl = static fn (Feed $feed): bool => !self::isSocial($feed);
        foreach ($this->startedAmong($isFetchedByUrl) as $feedId => $feed) {
            yield $feedId => new FetchTicketModel($feed->getUrl(), $feed->getEtag(), $feed->getLastModified());
        }
    }

    /** @return \Generator<int, Feed, mixed, void> */
    public function socialFeeds(): \Generator
    {
        yield from $this->startedAmong(self::isSocial(...));
    }

    /** @return list<int> every social feed's id, started or not */
    public function socialFeedIds(): array
    {
        return array_map(
            static fn (Feed $feed): int => $feed->requireId(),
            array_values(array_filter($this->feeds, self::isSocial(...))),
        );
    }

    /** @return list<int> */
    public function startedFeedIds(): array
    {
        return $this->startedFeedIds;
    }

    public function startedCount(): int
    {
        return \count($this->startedFeedIds);
    }

    public function skippedCount(): int
    {
        return \count($this->feeds) - $this->startedCount();
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

    private static function isSocial(Feed $feed): bool
    {
        return SourceFormat::isSocial($feed->getSourceFormat());
    }

    /**
     * @param \Closure(Feed): bool $belongs
     *
     * @return \Generator<int, Feed, mixed, void>
     */
    private function startedAmong(\Closure $belongs): \Generator
    {
        foreach ($this->feeds as $feed) {
            if (!$belongs($feed)) {
                continue;
            }
            if (!$this->mayStartAnother()) {
                return;
            }

            $this->startedFeedIds[] = $feed->requireId();

            yield $feed->requireId() => $feed;
        }
    }
}
