<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Curation\Recommendation\Stream\ForYouStreamSweep;
use App\Service\Curation\Recommendation\Stream\Model\ForYouStreamSweepReportModel;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'forYouStream', priority: 25)]
final readonly class ForYouStreamTickSweep implements MaintenanceSweepInterface
{
    private const int BUDGET_SECONDS = 10;

    public function __construct(
        private ForYouStreamSweep $sweep,
        private ClockInterface $clock,
    ) {
    }

    public function run(\DateTimeImmutable $deadline): ForYouStreamSweepReportModel
    {
        return $this->sweep->sweep(
            SweepBudgetModel::remainingUntil($deadline, $this->clock->now(), self::BUDGET_SECONDS),
        );
    }

    public function idleReport(): ForYouStreamSweepReportModel
    {
        return ForYouStreamSweepReportModel::none();
    }
}
