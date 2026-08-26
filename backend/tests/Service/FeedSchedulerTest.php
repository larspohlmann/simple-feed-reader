<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Feed;
use App\Enum\FeedStatus;
use App\Service\FeedScheduler;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testSuccessWithNewEntriesResetsIntervalToFloor(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setFetchIntervalMinutes(120);

        $this->scheduler->recordSuccess($feed, 3);

        // A source that shows life is polled at the floor at once, so the rest
        // of a burst interleaves instead of blocking the top of All items (#643).
        self::assertSame(5, $feed->getFetchIntervalMinutes());
        self::assertSame(0, $feed->getConsecutiveFailures());
        self::assertSame(FeedStatus::Active, $feed->getStatus());
        self::assertSame('2026-07-21 12:00:00', $feed->getLastFetchedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-21 12:00:00', $feed->getLastSuccessfulFetchAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-21 12:05:00', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));

        $feed->setFetchIntervalMinutes(8);
        $this->scheduler->recordSuccess($feed, 1);
        self::assertSame(5, $feed->getFetchIntervalMinutes());
    }

    public function testAThrottleCostsTheFeedNothingButItsPlaceInTheQueue(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setFetchIntervalMinutes(60);
        $feed->setLastFetchedAt(new \DateTimeImmutable('2026-07-21 11:00:00'));

        $this->scheduler->recordThrottled($feed, 90);

        self::assertSame('2026-07-21 12:01:30', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));
        // The feed is healthy; we asked too often. Counting this as a failure
        // would set the erroring status and an hours-long backoff.
        self::assertSame(0, $feed->getConsecutiveFailures());
        self::assertSame(FeedStatus::Active, $feed->getStatus());
        self::assertSame(60, $feed->getFetchIntervalMinutes());
        self::assertNull($feed->getLastErrorMessage());
        // Untouched, so the manual refresh's cooldown still measures the last
        // time content actually arrived.
        self::assertSame('2026-07-21 11:00:00', $feed->getLastFetchedAt()?->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{int|null, int, string}>
     */
    public static function throttleWaits(): iterable
    {
        yield 'the delay the site asked for' => [90, 60, '2026-07-21 12:01:30'];
        // Below a minute the retry would only draw a second 429, and a
        // multi-day wait is a feed nobody would see refresh again.
        yield 'a delay too short to help' => [2, 60, '2026-07-21 12:01:00'];
        yield 'a delay longer than a day' => [7 * 24 * 3600, 60, '2026-07-22 12:00:00'];
        yield 'no delay named' => [null, 60, '2026-07-21 13:00:00'];
        // Never sooner than the feed's own cadence: a daily feed that hits one
        // 429 must not be polled every quarter hour until it answers.
        yield 'no delay named, on a daily feed' => [null, 1440, '2026-07-22 12:00:00'];
    }

    #[DataProvider('throttleWaits')]
    public function testAThrottleWaitIsBoundedAtBothEnds(
        ?int $retryAfterSeconds,
        int $intervalMinutes,
        string $expectedNextFetch,
    ): void {
        $feed = new Feed('https://example.com/feed');
        $feed->setFetchIntervalMinutes($intervalMinutes);

        $this->scheduler->recordThrottled($feed, $retryAfterSeconds);

        self::assertSame($expectedNextFetch, $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testQuietSuccessGrowsIntervalUpToCeiling(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setFetchIntervalMinutes(60);

        $this->scheduler->recordSuccess($feed, 0);
        self::assertSame(90, $feed->getFetchIntervalMinutes());

        // The grow-on-empty branch is capped at 2 h, so the first fetch after a
        // quiet spell cannot accumulate more than that (#643).
        $feed->setFetchIntervalMinutes(300);
        $this->scheduler->recordSuccess($feed, 0);
        self::assertSame(120, $feed->getFetchIntervalMinutes());
    }

    public function testCorruptedIntervalCannotScheduleInThePast(): void
    {
        foreach ([0, -120] as $corrupted) {
            $feed = new Feed('https://example.com/feed');
            $feed->setFetchIntervalMinutes($corrupted);

            $this->scheduler->recordSuccess($feed, 0);

            self::assertSame(5, $feed->getFetchIntervalMinutes());
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

    /**
     * The #384 fix's core guarantee: only a fetch that actually delivered may
     * advance lastSuccessfulFetchAt. recordFailure() and recordGone() still
     * stamp lastFetchedAt — the manual-refresh cooldown and "has this feed
     * ever been fetched" both need that — but a failed or gone attempt is not
     * evidence about what the feed was serving, so lastSuccessfulFetchAt must
     * stay untouched.
     */
    public function testOnlyRecordSuccessAdvancesLastSuccessfulFetchAt(): void
    {
        $failed = new Feed('https://failed.example.com/feed');
        $this->scheduler->recordFailure($failed, 'timeout');
        self::assertNotNull($failed->getLastFetchedAt());
        self::assertNull($failed->getLastSuccessfulFetchAt());

        $gone = new Feed('https://gone.example.com/feed');
        $this->scheduler->recordGone($gone, 'HTTP 410 Gone');
        self::assertNotNull($gone->getLastFetchedAt());
        self::assertNull($gone->getLastSuccessfulFetchAt());
    }
}
