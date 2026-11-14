<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Social\Social\Model\PrivateFeedSyncBudgetModel;
use App\Service\Social\Social\Model\PrivateFeedSyncReportModel;
use App\Service\Social\Social\PrivateFeedSyncSweep;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'privateFeedSync', priority: 20)]
final readonly class PrivateFeedSyncTickSweep implements MaintenanceSweepInterface
{
    private const int MAX_ACCOUNTS = 1;

    public function __construct(
        private PrivateFeedSyncSweep $privateFeedSyncSweep,
        private ClockInterface $clock,
    ) {
    }

    public function run(\DateTimeImmutable $deadline): PrivateFeedSyncReportModel
    {
        return $this->privateFeedSyncSweep->sweep(
            PrivateFeedSyncBudgetModel::fittingBefore($deadline, $this->clock->now(), self::MAX_ACCOUNTS),
        );
    }

    public function idleReport(): PrivateFeedSyncReportModel
    {
        return PrivateFeedSyncReportModel::none();
    }
}
