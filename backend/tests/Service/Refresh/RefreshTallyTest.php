<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\RefreshTally;
use PHPUnit\Framework\TestCase;

final class RefreshTallyTest extends TestCase
{
    public function testAFreshTallyCountsNothing(): void
    {
        $tally = new RefreshTally();

        self::assertSame(0, $tally->fetched());
        self::assertSame(0, $tally->notModified());
        self::assertSame(0, $tally->failed());
        self::assertSame(0, $tally->throttled());
        self::assertSame(0, $tally->processed());
        self::assertSame(0, $tally->entriesCreated());
        self::assertFalse($tally->isAborted());
        self::assertSame([], $tally->faviconEligibleFeeds());
    }

    public function testEachOutcomeLandsInItsOwnCount(): void
    {
        $tally = new RefreshTally();

        $tally->record(FeedRefreshResultModel::fetched(3), new Feed('https://a.example.com/feed'));
        $tally->record(FeedRefreshResultModel::of(FeedOutcome::NotModified), new Feed('https://b.example.com/feed'));
        $tally->record(FeedRefreshResultModel::of(FeedOutcome::Failed), new Feed('https://c.example.com/feed'));
        $tally->record(FeedRefreshResultModel::of(FeedOutcome::Throttled), new Feed('https://d.example.com/feed'));

        self::assertSame(1, $tally->fetched());
        self::assertSame(1, $tally->notModified());
        self::assertSame(1, $tally->failed());
        self::assertSame(1, $tally->throttled());
        self::assertSame(4, $tally->processed());
        self::assertSame(3, $tally->entriesCreated());
        self::assertFalse($tally->isAborted());
    }

    public function testAnAbortedFeedCountsAsFailedButNotAsProcessed(): void
    {
        $tally = new RefreshTally();
        $fetched = new Feed('https://a.example.com/feed');

        $tally->record(FeedRefreshResultModel::fetched(2), $fetched);
        $tally->record(FeedRefreshResultModel::of(FeedOutcome::Aborted), new Feed('https://b.example.com/feed'));

        self::assertSame(1, $tally->failed());
        self::assertSame(1, $tally->processed());
        self::assertSame(2, $tally->entriesCreated());
        self::assertTrue($tally->isAborted());
        self::assertSame([$fetched], $tally->faviconEligibleFeeds());
    }

    public function testOnlyFeedsThatBroughtContentAreFaviconEligible(): void
    {
        $tally = new RefreshTally();
        $fetched = new Feed('https://a.example.com/feed');
        $unchanged = new Feed('https://b.example.com/feed');

        $tally->record(FeedRefreshResultModel::fetched(0), $fetched);
        $tally->record(FeedRefreshResultModel::of(FeedOutcome::Failed), new Feed('https://c.example.com/feed'));
        $tally->record(FeedRefreshResultModel::of(FeedOutcome::NotModified), $unchanged);
        $tally->record(FeedRefreshResultModel::of(FeedOutcome::Throttled), new Feed('https://d.example.com/feed'));

        self::assertSame([$fetched, $unchanged], $tally->faviconEligibleFeeds());
    }
}
