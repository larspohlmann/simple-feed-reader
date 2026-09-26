<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Feed;
use App\Service\Fetch\HostThrottle;
use Symfony\Component\Clock\ClockInterface;

final readonly class FeedScheduler
{
    private const int FLOOR_MINUTES = 5;
    private const int CEILING_MINUTES = 120;       // 2 h
    private const int FAILURE_CAP_MINUTES = 10080; // 7 days
    private const int FAILURES_UNTIL_GONE = 30;
    private const int MAX_BACKOFF_EXPONENT = 9;
    private const int SECONDS_PER_MINUTE = 60;

    public function __construct(
        private ClockInterface $clock,
        private HostThrottle $hostThrottle,
    ) {
    }

    /**
     * @throws \DateMalformedStringException
     */
    public function recordSuccess(Feed $feed, int $newEntryCount): void
    {
        // New entries reset to the floor at once, not by halving, or a burst blocks the top of All items (#643).
        // The grow branch keeps the floor guard: a stored interval <= 0 would otherwise refetch the feed every run.
        $interval = $newEntryCount > 0
            ? self::FLOOR_MINUTES
            : max(
                self::FLOOR_MINUTES,
                min(self::CEILING_MINUTES, (int) round($feed->getFetchIntervalMinutes() * 1.5)),
            );

        $now = $this->clock->now();
        $feed->recordSuccessfulFetch($now, $interval);
        if ($newEntryCount > 0) {
            $feed->recordNewEntries($now);
        }
    }

    /**
     * A 429 rations, it does not fail: only nextFetchAt moves, or one 429 silences a working feed for hours (#290).
     * lastFetchedAt stays too: the manual refresh's cooldown reads it, and stamping it would block a retry by hand.
     *
     * @throws \DateMalformedStringException
     */
    public function recordThrottled(Feed $feed, ?int $retryAfterSeconds): void
    {
        $hostWait = $this->hostThrottle->record($feed->getUrl(), $retryAfterSeconds);
        // Reddit resets in seconds, so only this feed, not the whole host, waits out its own cadence.
        $wait = $retryAfterSeconds === null ? max($hostWait, $this->cadenceSeconds($feed)) : $hostWait;

        $feed->scheduleNextFetchAt($this->clock->now()->modify(sprintf('+%d seconds', $wait)));
    }

    private function cadenceSeconds(Feed $feed): int
    {
        return min(HostThrottle::MAXIMUM_WAIT_SECONDS, $feed->getFetchIntervalMinutes() * self::SECONDS_PER_MINUTE);
    }

    /**
     * @throws \DateMalformedStringException
     */
    public function recordFailure(Feed $feed, string $message): void
    {
        $failures = $feed->getConsecutiveFailures() + 1;
        $now = $this->clock->now();

        if ($failures >= self::FAILURES_UNTIL_GONE) {
            $feed->markGone($now, $message);

            return;
        }

        $feed->recordFailedFetch($now, $message, $this->backoffMinutes($feed, $failures));
    }

    public function recordGone(Feed $feed, string $message): void
    {
        $feed->markGone($this->clock->now(), $message);
    }

    private function backoffMinutes(Feed $feed, int $failures): int
    {
        return (int) min(
            self::FAILURE_CAP_MINUTES,
            max($feed->getFetchIntervalMinutes(), self::FLOOR_MINUTES)
                * (2 ** min($failures, self::MAX_BACKOFF_EXPONENT)),
        );
    }
}
