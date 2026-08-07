<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Service\Worker\Message\AdvanceRecommendationRuns;
use App\Service\Worker\Message\PurgeFailedMessages;
use App\Service\Worker\Message\RefreshDueFeeds;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * The worker container's whole job description (#311): consume this schedule
 * with `messenger:consume scheduler_worker`. Three entries by decision:
 * the recommendation sweep, the feed refresh sweep (the 2026-08-07 decision
 * that brings scheduled refresh to worker-equipped installs; poll-only
 * installs stay manual), and failure-transport housekeeping. Scheduled
 * recommendation runs stay out (#308: manual button only).
 */
#[AsSchedule('worker')]
final readonly class WorkerSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('10 seconds', new AdvanceRecommendationRuns()))
            ->add(RecurringMessage::every('5 minutes', new RefreshDueFeeds()))
            ->add(RecurringMessage::every('1 day', new PurgeFailedMessages()));
    }
}
