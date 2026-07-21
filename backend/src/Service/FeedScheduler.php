<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Feed;
use App\Enum\FeedStatus;
use Symfony\Component\Clock\ClockInterface;

/**
 * Owns all fetch-schedule state transitions on Feed: adaptive interval on
 * success, exponential backoff on failure, and the "gone" terminal state.
 */
final class FeedScheduler
{
    private const FLOOR_MINUTES = 30;
    private const CEILING_MINUTES = 1440;      // 24 h
    private const FAILURE_CAP_MINUTES = 10080; // 7 days
    private const FAILURES_UNTIL_GONE = 30;
    private const MAX_BACKOFF_EXPONENT = 9;
    private const ERROR_MESSAGE_MAX = 1000;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

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
