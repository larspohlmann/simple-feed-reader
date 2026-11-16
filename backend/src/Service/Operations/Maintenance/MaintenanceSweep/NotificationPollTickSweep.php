<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Social\Notification\Model\NotificationPollBudgetModel;
use App\Service\Social\Notification\Model\NotificationPollReportModel;
use App\Service\Social\Notification\NotificationPollSweep;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'notificationPoll', priority: 15)]
final readonly class NotificationPollTickSweep implements MaintenanceSweepInterface
{
    private const int MAX_ACCOUNTS = 1;

    public function __construct(
        private NotificationPollSweep $notificationPollSweep,
        private ClockInterface $clock,
    ) {
    }

    public function run(\DateTimeImmutable $deadline): NotificationPollReportModel
    {
        return $this->notificationPollSweep->sweep(
            NotificationPollBudgetModel::fittingBefore($deadline, $this->clock->now(), self::MAX_ACCOUNTS),
        );
    }

    public function idleReport(): NotificationPollReportModel
    {
        return NotificationPollReportModel::none();
    }
}
