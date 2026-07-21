<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Feed;
use App\Enum\FeedStatus;
use App\Service\FeedScheduler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class FeedSchedulerTest extends TestCase
{
    private MockClock $clock;
    private FeedScheduler $scheduler;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        $this->scheduler = new FeedScheduler($this->clock);
    }

    public function testSuccessWithNewEntriesHalvesIntervalDownToFloor(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setFetchIntervalMinutes(120);

        $this->scheduler->recordSuccess($feed, 3);

        self::assertSame(60, $feed->getFetchIntervalMinutes());
        self::assertSame(0, $feed->getConsecutiveFailures());
        self::assertSame(FeedStatus::Active, $feed->getStatus());
        self::assertSame('2026-07-21 12:00:00', $feed->getLastFetchedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-21 13:00:00', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));

        $feed->setFetchIntervalMinutes(40);
        $this->scheduler->recordSuccess($feed, 1);
        self::assertSame(30, $feed->getFetchIntervalMinutes());
    }

    public function testQuietSuccessGrowsIntervalUpToCeiling(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setFetchIntervalMinutes(60);

        $this->scheduler->recordSuccess($feed, 0);
        self::assertSame(90, $feed->getFetchIntervalMinutes());

        $feed->setFetchIntervalMinutes(1200);
        $this->scheduler->recordSuccess($feed, 0);
        self::assertSame(1440, $feed->getFetchIntervalMinutes());
    }

    public function testCorruptedIntervalCannotScheduleInThePast(): void
    {
        foreach ([0, -120] as $corrupted) {
            $feed = new Feed('https://example.com/feed');
            $feed->setFetchIntervalMinutes($corrupted);

            $this->scheduler->recordSuccess($feed, 0);

            self::assertSame(30, $feed->getFetchIntervalMinutes());
            self::assertGreaterThan($this->clock->now(), $feed->getNextFetchAt());
        }
    }

    public function testSuccessClearsPreviousFailureState(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setConsecutiveFailures(5);
        $feed->setLastErrorMessage('boom');
        $feed->setStatus(FeedStatus::Erroring);

        $this->scheduler->recordSuccess($feed, 0);

        self::assertSame(0, $feed->getConsecutiveFailures());
        self::assertNull($feed->getLastErrorMessage());
        self::assertSame(FeedStatus::Active, $feed->getStatus());
    }

    public function testFailureBacksOffExponentially(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setFetchIntervalMinutes(60);

        $this->scheduler->recordFailure($feed, 'timeout');

        self::assertSame(1, $feed->getConsecutiveFailures());
        self::assertSame(FeedStatus::Erroring, $feed->getStatus());
        self::assertSame('timeout', $feed->getLastErrorMessage());
        // 60 * 2^1 = 120 minutes
        self::assertSame('2026-07-21 14:00:00', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));

        $this->scheduler->recordFailure($feed, 'timeout again');
        // 60 * 2^2 = 240 minutes
        self::assertSame('2026-07-21 16:00:00', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testBackoffIsCappedAtSevenDays(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setFetchIntervalMinutes(1440);
        $feed->setConsecutiveFailures(10);

        $this->scheduler->recordFailure($feed, 'still broken');

        $cap = $this->clock->now()->modify('+10080 minutes');
        self::assertSame($cap->format('Y-m-d H:i:s'), $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testThirtiethFailureMarksFeedGone(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setConsecutiveFailures(29);

        $this->scheduler->recordFailure($feed, 'the end');

        self::assertSame(FeedStatus::Gone, $feed->getStatus());
        self::assertSame(30, $feed->getConsecutiveFailures());
        self::assertNull($feed->getNextFetchAt());
    }

    public function testLongErrorMessageIsTruncated(): void
    {
        $feed = new Feed('https://example.com/feed');

        $this->scheduler->recordFailure($feed, str_repeat('x', 5000));

        self::assertSame(1000, mb_strlen((string) $feed->getLastErrorMessage()));
    }

    public function testRecordGone(): void
    {
        $feed = new Feed('https://example.com/feed');

        $this->scheduler->recordGone($feed, 'HTTP 410 Gone');

        self::assertSame(FeedStatus::Gone, $feed->getStatus());
        self::assertNull($feed->getNextFetchAt());
        self::assertSame('HTTP 410 Gone', $feed->getLastErrorMessage());
    }
}
