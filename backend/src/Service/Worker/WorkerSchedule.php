<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Service\Worker\Message\AdvanceRecommendationRuns;
use App\Service\Worker\Message\PurgeFailedMessages;
use App\Service\Worker\Message\RefreshDueFeeds;
use App\Service\Worker\Message\StartDueRecommendationRuns;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The worker container's whole job description (#311): consume this schedule
 * with `messenger:consume scheduler_worker`. Four entries by decision: the
 * recommendation sweep is now two of them -- the ten-second advance sweep and
 * the five-minute start-due sweep (#333) -- plus the feed refresh sweep (the
 * 2026-08-07 decision that brings scheduled refresh to worker-equipped
 * installs; poll-only installs stay manual) and failure-transport
 * housekeeping.
 *
 * The recommendation START sweep (#333) supersedes #308's "manual button
 * only" as an opt-in: it starts a run only for an account that chose a
 * cadence in its For You settings, and the ten-second sweep above then
 * advances it. An account that never chose one is never started.
 */
#[AsSchedule('worker')]
final readonly class WorkerSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $schedulerStateCache,
    ) {
    }

    /**
     * `stateful()` is load-bearing, not a tuning knob. Without it every entry
     * anchors its series at PROCESS START, because PeriodicalTrigger takes
     * its `from` from the checkpoint and an in-process ArrayAdapter starts
     * every process with a fresh one. Both compose files recycle the consumer
     * hourly with `--time-limit=3600`, so the daily housekeeping entry
     * re-anchored to "now + 24 h" every hour and could never fire at all --
     * `messenger_messages` then grew without bound, which is precisely what
     * that entry exists to prevent (#311 final review, Critical 1). A
     * persisted checkpoint keeps the original `from`, so the daily entry
     * comes due 24 h after the FIRST-EVER start no matter how often the
     * consumer is recycled, and the other three entries stop losing their
     * cadence at every restart as well.
     *
     * The pool is a filesystem one under CACHE_DIRECTORY (var/cache-pools),
     * the same place the rate limiter and ALTCHA replay pools live, so the
     * prod entrypoint's `rm -rf var/cache/prod` never resets it.
     *
     * `processOnlyLastMissedRun()` is the necessary companion: a persisted
     * checkpoint means a consumer that was down replays every occurrence it
     * missed, and an hour of downtime owes the ten-second entry 360 firings.
     * All four messages are SWEEPS -- each one does whatever is outstanding
     * at the moment it runs -- so catching up means running once, now.
     */
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('10 seconds', new AdvanceRecommendationRuns()))
            ->add(RecurringMessage::every('5 minutes', new StartDueRecommendationRuns()))
            ->add(RecurringMessage::every('5 minutes', new RefreshDueFeeds()))
            ->add(RecurringMessage::every('1 day', new PurgeFailedMessages()))
            ->stateful($this->schedulerStateCache)
            ->processOnlyLastMissedRun(true);
    }
}
