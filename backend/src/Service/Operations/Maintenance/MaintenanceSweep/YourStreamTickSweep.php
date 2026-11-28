<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Curation\YourStream\Model\YourStreamSweepReportModel;
use App\Service\Curation\YourStream\YourStreamSweep;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'forYouStream', priority: 25)]
final readonly class YourStreamTickSweep implements MaintenanceSweepInterface
{
    private const int BUDGET_SECONDS = 10;

    public function __construct(
        private YourStreamSweep $sweep,
        private ClockInterface $clock,
    ) {
    }

    public function run(\DateTimeImmutable $deadline): YourStreamSweepReportModel
    {
        return $this->sweep->sweep(
            SweepBudgetModel::remainingUntil($deadline, $this->clock->now(), self::BUDGET_SECONDS),
        );
    }

    public function idleReport(): YourStreamSweepReportModel
    {
        return YourStreamSweepReportModel::none();
    }
}
