<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Social\Social\HiddenProfileSwitchSweep;
use App\Service\Social\Social\Model\HiddenProfileSwitchReportModel;
use App\Service\Social\Social\Model\ProfileCallBudgetModel;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'hiddenProfiles', priority: 40)]
final readonly class HiddenProfilesTickSweep implements MaintenanceSweepInterface
{
    private const int MAX_CALLS = 1;

    public function __construct(
        private HiddenProfileSwitchSweep $hiddenProfileSwitchSweep,
        private ClockInterface $clock,
    ) {
    }

    public function run(\DateTimeImmutable $deadline): HiddenProfileSwitchReportModel
    {
        return $this->hiddenProfileSwitchSweep->sweep(
            ProfileCallBudgetModel::fittingBefore($deadline, $this->clock->now(), self::MAX_CALLS),
        );
    }

    public function idleReport(): HiddenProfileSwitchReportModel
    {
        return HiddenProfileSwitchReportModel::none();
    }
}
