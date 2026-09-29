<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Service\Worker\Message\AdvanceRecommendationRuns;
use App\Service\Worker\Message\PurgeFailedMessages;
use App\Service\Worker\Message\RefreshDueFeeds;
use App\Service\Worker\Message\SendDueDigests;
use App\Service\Worker\Message\StartDueRecommendationRuns;
use App\Service\Worker\Message\SweepSavedSearchMemberships;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/** The worker container's schedule, consumed by `messenger:consume scheduler_worker`. */
#[AsSchedule('worker')]
final readonly class WorkerSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $schedulerStateCache,
    ) {
    }

    /**
     * stateful() is load-bearing: the consumer restarts hourly (--time-limit=3600), and an in-process checkpoint
     * re-anchors every entry at each start, so the daily one would never fire. The pool must outlive var/cache/prod.
     * Every message stays a property-less sweep (a failed copy never goes stale), so one catch-up firing suffices.
     */
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('10 seconds', new AdvanceRecommendationRuns()))
            ->add(RecurringMessage::every('5 minutes', new StartDueRecommendationRuns()))
            ->add(RecurringMessage::every('5 minutes', new RefreshDueFeeds()))
            ->add(RecurringMessage::every('1 day', new PurgeFailedMessages()))
            ->add(RecurringMessage::every('1 hour', new SendDueDigests()))
            ->add(RecurringMessage::every('1 minute', new SweepSavedSearchMemberships()))
            ->stateful($this->schedulerStateCache)
            ->processOnlyLastMissedRun(true);
    }
}
