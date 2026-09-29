<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\Pass\RefreshPass;
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
        $feeds = $this->feeds(10);
        $pass = $this->pass($feeds, self::WHOLE_BATCH_BUDGET);
        iterator_to_array($pass->tickets());
        // Every count gets a distinct value, so a swapped argument in RefreshPass::aborted() fails this test.
        $pass->tally->record(FeedRefreshResultModel::fetched(1), $feeds[0]);
        $pass->tally->record(FeedRefreshResultModel::fetched(1), $feeds[1]);
        $pass->tally->record(FeedRefreshResultModel::fetched(1), $feeds[2]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::NotModified), $feeds[3]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::NotModified), $feeds[4]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::Throttled), $feeds[5]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::Failed), $feeds[6]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::Failed), $feeds[7]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::Failed), $feeds[8]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::Aborted), $feeds[9]);

        $report = $pass->abortedDuringOutcomes();

        self::assertSame('aborted', $report->status);
        self::assertSame(10, $report->total);
        self::assertSame(3, $report->fetched);
        self::assertSame(2, $report->notModified);
        self::assertSame(4, $report->failed);
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
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::Throttled), $one);

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
        $feeds = $this->feeds(6);
        $pass = $this->pass($feeds, self::ONE_FEED_BUDGET);
        iterator_to_array($pass->tickets());
        // Every count gets a distinct value, so a swapped argument in RefreshPass::finished() fails this test.
        $pass->tally->record(FeedRefreshResultModel::fetched(1), $feeds[0]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::NotModified), $feeds[1]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::NotModified), $feeds[2]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::Failed), $feeds[3]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::Failed), $feeds[3]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::Failed), $feeds[3]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::Throttled), $feeds[4]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::Throttled), $feeds[4]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::Throttled), $feeds[4]);
        $pass->tally->record(FeedRefreshResultModel::of(FeedOutcome::Throttled), $feeds[5]);

        $report = $pass->finished(7, 8);

        self::assertSame('partial', $report->status);
        self::assertSame(6, $report->total);
        self::assertSame(1, $report->fetched);
        self::assertSame(2, $report->notModified);
        self::assertSame(3, $report->failed);
        self::assertSame(4, $report->throttled);
        self::assertSame(5, $report->skippedForBudget);
        self::assertSame(7, $report->remaining);
        self::assertSame(8, $report->pruned);
    }

    /**
     * @return list<Feed>
     */
    private function feeds(int $count): array
    {
        $feeds = [];
        for ($index = 1; $index <= $count; ++$index) {
            $feed = new Feed(sprintf('https://feed%d.example.com/rss', $index));
            $this->entityManager->persist($feed);
            $feeds[] = $feed;
        }
        $this->entityManager->flush();

        return $feeds;
    }

    /**
     * @param list<Feed> $feeds
     */
    private function pass(array $feeds, int $budgetSeconds): RefreshPass
    {
        return new RefreshPass($feeds, $this->clock, $this->clock->now()->getTimestamp() + $budgetSeconds);
    }
}
