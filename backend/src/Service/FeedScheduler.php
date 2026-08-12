<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Feed;
use App\Enum\FeedStatus;
use Symfony\Component\Clock\ClockInterface;

/**
 * Owns all fetch-schedule state transitions on Feed: adaptive interval on
 * success, a wait on a rationed request, exponential backoff on failure, and
 * the "gone" terminal state.
 */
final class FeedScheduler
{
    private const int FLOOR_MINUTES = 5;
    private const int CEILING_MINUTES = 360;       // 6 h
    private const int FAILURE_CAP_MINUTES = 10080; // 7 days
    private const int FAILURES_UNTIL_GONE = 30;
    private const int MAX_BACKOFF_EXPONENT = 9;
    private const int ERROR_MESSAGE_MAX = 1000;

    /** The bounds on a wait a rationing site may ask for. */
    private const int THROTTLE_FLOOR_SECONDS = 60;
    private const int THROTTLE_CEILING_SECONDS = 86400;
    private const int SECONDS_PER_MINUTE = 60;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    /**
     * @throws \DateMalformedStringException
     */
    public function recordSuccess(Feed $feed, int $newEntryCount): void
    {
        // The floor applies to both branches: without it a stored interval of
        // <= 0 (corruption, manual edit) would survive the *1.5 growth and set
        // nextFetchAt <= now, refetching the feed on every single run.
        $interval = $newEntryCount > 0
            ? max(self::FLOOR_MINUTES, intdiv($feed->getFetchIntervalMinutes(), 2))
            : max(
                self::FLOOR_MINUTES,
                min(self::CEILING_MINUTES, (int) round($feed->getFetchIntervalMinutes() * 1.5)),
            );

        $now = $this->clock->now();
        $feed->setFetchIntervalMinutes($interval);
        $feed->setConsecutiveFailures(0);
        $feed->setLastErrorMessage(null);
        $feed->setStatus(FeedStatus::Active);
        $feed->setLastFetchedAt($now);
        $feed->setNextFetchAt($now->modify(sprintf('+%d minutes', $interval)));
    }

    /**
     * The site is rationing requests, not broken. This writes one field — when
     * we may ask again. No failure counted, no erroring status, no error
     * message, no backoff growth: anything else would let one 429 cost a
     * working feed hours of silence, which is what emptied the Reddit feeds in
     * #290.
     *
     * lastFetchedAt stays as it was: it records when content last arrived, and
     * the manual refresh's cooldown reads it, so stamping it here would also
     * lock the user out of retrying by hand.
     *
     * @throws \DateMalformedStringException
     */
    public function recordThrottled(Feed $feed, ?int $retryAfterSeconds): void
    {
        // A site that named no delay is asked again no sooner than it would
        // have been anyway. Polling a host that just said "less" every quarter
        // hour, when its own cadence had grown to daily, is asking for more.
        $wait = min(
            self::THROTTLE_CEILING_SECONDS,
            max(
                self::THROTTLE_FLOOR_SECONDS,
                $retryAfterSeconds ?? $feed->getFetchIntervalMinutes() * self::SECONDS_PER_MINUTE,
            ),
        );

        $feed->setNextFetchAt($this->clock->now()->modify(sprintf('+%d seconds', $wait)));
    }

    /**
     * @throws \DateMalformedStringException
     */
    public function recordFailure(Feed $feed, string $message): void
    {
        $failures = $feed->getConsecutiveFailures() + 1;
        $now = $this->clock->now();

        $feed->setConsecutiveFailures($failures);
        $feed->setLastErrorMessage(mb_substr($message, 0, self::ERROR_MESSAGE_MAX));
        $feed->setLastFetchedAt($now);

        if ($failures >= self::FAILURES_UNTIL_GONE) {
            $feed->setStatus(FeedStatus::Gone);
            $feed->setNextFetchAt(null);

            return;
        }

        $feed->setStatus(FeedStatus::Erroring);
        $backoffMinutes = (int) min(
            self::FAILURE_CAP_MINUTES,
            max($feed->getFetchIntervalMinutes(), self::FLOOR_MINUTES)
                * (2 ** min($failures, self::MAX_BACKOFF_EXPONENT)),
        );
        $feed->setNextFetchAt($now->modify(sprintf('+%d minutes', $backoffMinutes)));
    }

    public function recordGone(Feed $feed, string $message): void
    {
        $now = $this->clock->now();
        $feed->setStatus(FeedStatus::Gone);
        $feed->setConsecutiveFailures($feed->getConsecutiveFailures() + 1);
        $feed->setLastErrorMessage(mb_substr($message, 0, self::ERROR_MESSAGE_MAX));
        $feed->setLastFetchedAt($now);
        $feed->setNextFetchAt(null);
    }
}
