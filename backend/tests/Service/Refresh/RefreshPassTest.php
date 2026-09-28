<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Service\Refresh\BudgetedFeedQueue;
use App\Service\Refresh\FeedOutcome;
use App\Service\Refresh\FeedRefreshResult;
use App\Service\Refresh\RefreshPass;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class RefreshPassTest extends DbTestCase
{
    /** Below BudgetedFeedQueue's 10-second margin: only the first feed ever starts. */
    private const int ONE_FEED_BUDGET = 5;

    private const int WHOLE_BATCH_BUDGET = 300;

    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
    }

    public function testFindsEachFeedByItsId(): void
    {
        [$one, $two] = $this->feeds(2);
        $pass = $this->pass([$one, $two], self::WHOLE_BATCH_BUDGET);

        self::assertSame($two, $pass->feed($two->requireId()));
        self::assertSame($one, $pass->feed($one->requireId()));
    }

    public function testStartedFeedIdsAreTheOnesTheBudgetLetThrough(): void
    {
        [$one, $two] = $this->feeds(2);
        $pass = $this->pass([$one, $two], self::ONE_FEED_BUDGET);

        self::assertCount(1, iterator_to_array($pass->tickets()));
        self::assertSame([$one->requireId()], $pass->startedFeedIds());
    }

    public function testAnAbortDuringOutcomesLeavesEveryUnprocessedFeedRemaining(): void
    {
        [$one, $two, $three, $four] = $this->feeds(4);
        $pass = $this->pass([$one, $two, $three, $four], self::WHOLE_BATCH_BUDGET);
        iterator_to_array($pass->tickets());
        $pass->tally->record(FeedRefreshResult::fetched(2), $one);
        $pass->tally->record(FeedRefreshResult::of(FeedOutcome::NotModified), $two);
        $pass->tally->record(FeedRefreshResult::of(FeedOutcome::Throttled), $three);
        $pass->tally->record(FeedRefreshResult::of(FeedOutcome::Aborted), $four);

        $report = $pass->abortedDuringOutcomes();

        self::assertSame('aborted', $report->status);
        self::assertSame(4, $report->total);
        self::assertSame(1, $report->fetched);
        self::assertSame(1, $report->notModified);
        self::assertSame(1, $report->failed);
        self::assertSame(1, $report->throttled);
        self::assertSame(0, $report->skippedForBudget);
        self::assertSame(1, $report->remaining);
        self::assertSame(0, $report->pruned);
    }

    public function testAnAbortAfterOutcomesLeavesTheFeedsTheBudgetNeverStartedRemaining(): void
    {
        [$one, $two, $three] = $this->feeds(3);
        $pass = $this->pass([$one, $two, $three], self::ONE_FEED_BUDGET);
        iterator_to_array($pass->tickets());
        $pass->tally->record(FeedRefreshResult::of(FeedOutcome::Throttled), $one);

        $report = $pass->abortedAfterOutcomes();

        self::assertSame('aborted', $report->status);
        self::assertSame(3, $report->total);
        self::assertSame(0, $report->fetched);
        self::assertSame(0, $report->notModified);
        self::assertSame(0, $report->failed);
        self::assertSame(1, $report->throttled);
        self::assertSame(0, $report->skippedForBudget);
        self::assertSame(2, $report->remaining);
        self::assertSame(0, $report->pruned);
    }

    public function testTheTwoAbortsCountRemainingDifferentlyWhenAStartedFeedWasNeverRecorded(): void
    {
        [$one, $two, $three] = $this->feeds(3);
        $pass = $this->pass([$one, $two, $three], self::ONE_FEED_BUDGET);
        iterator_to_array($pass->tickets());

        self::assertSame(3, $pass->abortedDuringOutcomes()->remaining);
        self::assertSame(2, $pass->abortedAfterOutcomes()->remaining);
    }

    public function testAFinishedPassCarriesTheTallyTheBudgetSkipsAndTheCallersCounts(): void
    {
        [$one, $two, $three] = $this->feeds(3);
        $pass = $this->pass([$one, $two, $three], self::ONE_FEED_BUDGET);
        iterator_to_array($pass->tickets());
        $pass->tally->record(FeedRefreshResult::of(FeedOutcome::Failed), $one);

        $report = $pass->finished(4, 7);

        self::assertSame('partial', $report->status);
        self::assertSame(3, $report->total);
        self::assertSame(0, $report->fetched);
        self::assertSame(0, $report->notModified);
        self::assertSame(1, $report->failed);
        self::assertSame(0, $report->throttled);
        self::assertSame(2, $report->skippedForBudget);
        self::assertSame(4, $report->remaining);
        self::assertSame(7, $report->pruned);
    }

    /**
     * @return list<Feed>
     */
    private function feeds(int $count): array
    {
        $feeds = [];
        for ($index = 1; $index <= $count; ++$index) {
            $feed = new Feed(sprintf('https://feed%d.example.com/rss', $index));
            $this->em->persist($feed);
            $feeds[] = $feed;
        }
        $this->em->flush();

        return $feeds;
    }

    /**
     * @param list<Feed> $feeds
     */
    private function pass(array $feeds, int $budgetSeconds): RefreshPass
    {
        return new RefreshPass(
            $feeds,
            new BudgetedFeedQueue($feeds, $this->clock, $this->clock->now()->getTimestamp() + $budgetSeconds),
        );
    }
}
