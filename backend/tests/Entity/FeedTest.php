<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Feed;
use App\Enum\FeedStatus;
use PHPUnit\Framework\TestCase;

final class FeedTest extends TestCase
{
    public function testSourceFormatDefaultsToXmlAndIsMutable(): void
    {
        $feed = new Feed('https://example.com/page');
        self::assertSame('xml', $feed->getSourceFormat());
        $feed->setSourceFormat('scraped');
        self::assertSame('scraped', $feed->getSourceFormat());
    }

    public function testAFeedStartsWithNoImage(): void
    {
        self::assertNull((new Feed('https://example.com/feed.xml'))->getImageUrl());
    }

    public function testTheImageUrlRoundTrips(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setImageUrl('https://example.com/logo.png');

        self::assertSame('https://example.com/logo.png', $feed->getImageUrl());
    }

    public function testASuccessfulFetchEndsAFailureStreakAndSchedulesTheNextFetch(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->recordFailedFetch(new \DateTimeImmutable('2026-07-20 08:00:00'), 'HTTP 500', 45);
        $feed->recordFailedFetch(new \DateTimeImmutable('2026-07-20 09:00:00'), 'HTTP 502', 90);

        $feed->recordSuccessfulFetch(new \DateTimeImmutable('2026-07-21 10:00:00'), 35);

        self::assertSame(FeedStatus::Active, $feed->getStatus());
        self::assertSame(0, $feed->getConsecutiveFailures());
        self::assertNull($feed->getLastErrorMessage());
        self::assertSame(35, $feed->getFetchIntervalMinutes());
        self::assertEquals(new \DateTimeImmutable('2026-07-21 10:00:00'), $feed->getLastFetchedAt());
        self::assertEquals(new \DateTimeImmutable('2026-07-21 10:00:00'), $feed->getLastSuccessfulFetchAt());
        self::assertEquals(new \DateTimeImmutable('2026-07-21 10:35:00'), $feed->getNextFetchAt());
    }

    public function testAFailedFetchCountsTheFailureAndBacksOffWithoutClaimingSuccess(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->recordSuccessfulFetch(new \DateTimeImmutable('2026-07-19 07:00:00'), 20);
        $feed->recordFailedFetch(new \DateTimeImmutable('2026-07-20 08:00:00'), 'HTTP 500', 45);

        $feed->recordFailedFetch(new \DateTimeImmutable('2026-07-20 09:00:00'), 'HTTP 502', 90);

        self::assertSame(FeedStatus::Erroring, $feed->getStatus());
        self::assertSame(2, $feed->getConsecutiveFailures());
        self::assertSame('HTTP 502', $feed->getLastErrorMessage());
        self::assertSame(20, $feed->getFetchIntervalMinutes());
        self::assertEquals(new \DateTimeImmutable('2026-07-20 09:00:00'), $feed->getLastFetchedAt());
        self::assertEquals(new \DateTimeImmutable('2026-07-19 07:00:00'), $feed->getLastSuccessfulFetchAt());
        self::assertEquals(new \DateTimeImmutable('2026-07-20 10:30:00'), $feed->getNextFetchAt());
    }

    public function testAGoneFeedIsNeverScheduledAgain(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->recordFailedFetch(new \DateTimeImmutable('2026-07-20 08:00:00'), 'HTTP 500', 45);

        $feed->markGone(new \DateTimeImmutable('2026-07-21 10:00:00'), 'HTTP 410 Gone');

        self::assertSame(FeedStatus::Gone, $feed->getStatus());
        self::assertNull($feed->getNextFetchAt());
        self::assertSame(2, $feed->getConsecutiveFailures());
        self::assertSame('HTTP 410 Gone', $feed->getLastErrorMessage());
        self::assertEquals(new \DateTimeImmutable('2026-07-21 10:00:00'), $feed->getLastFetchedAt());
        self::assertNull($feed->getLastSuccessfulFetchAt());
    }

    public function testAFailureMessageIsCappedAtAThousandCharacters(): void
    {
        $feed = new Feed('https://example.com/feed.xml');

        $feed->recordFailedFetch(new \DateTimeImmutable('2026-07-20 08:00:00'), 'é' . str_repeat('x', 1000), 45);
        self::assertStringStartsWith('é', (string) $feed->getLastErrorMessage());
        self::assertSame(1000, mb_strlen((string) $feed->getLastErrorMessage()));

        $feed->markGone(new \DateTimeImmutable('2026-07-21 10:00:00'), 'ü' . str_repeat('x', 1000));
        self::assertStringStartsWith('ü', (string) $feed->getLastErrorMessage());
        self::assertSame(1000, mb_strlen((string) $feed->getLastErrorMessage()));
    }

    public function testNewEntriesStampOnlyTheirArrival(): void
    {
        $feed = new Feed('https://example.com/feed.xml');

        $feed->recordNewEntries(new \DateTimeImmutable('2026-07-21 10:00:00'));

        self::assertEquals(new \DateTimeImmutable('2026-07-21 10:00:00'), $feed->getLastNewEntryAt());
        self::assertNull($feed->getLastFetchedAt());
        self::assertNull($feed->getNextFetchAt());
    }

    public function testSchedulingTheNextFetchLeavesTheFeedsHealthAlone(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->recordFailedFetch(new \DateTimeImmutable('2026-07-20 08:00:00'), 'HTTP 500', 45);

        $feed->scheduleNextFetchAt(new \DateTimeImmutable('2026-07-20 08:01:30'));

        self::assertEquals(new \DateTimeImmutable('2026-07-20 08:01:30'), $feed->getNextFetchAt());
        self::assertSame(FeedStatus::Erroring, $feed->getStatus());
        self::assertSame(1, $feed->getConsecutiveFailures());
        self::assertSame('HTTP 500', $feed->getLastErrorMessage());
    }
}
