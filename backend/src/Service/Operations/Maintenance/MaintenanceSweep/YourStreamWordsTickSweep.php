<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Curation\YourStream\Model\YourStreamWordSweepReportModel;
use App\Service\Curation\YourStream\YourStreamWordSweep;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'yourStreamWords', priority: 7)]
final readonly class YourStreamWordsTickSweep implements MaintenanceSweepInterface
{
    public function __construct(private YourStreamWordSweep $yourStreamWords, private ClockInterface $clock)
    {
    }

    public function run(\DateTimeImmutable $deadline): YourStreamWordSweepReportModel
    {
        return $this->yourStreamWords->sweep(
            SweepBudgetModel::remainingUntil(
                $deadline,
                $this->clock->now(),
                YourStreamWordSweep::UNHURRIED_BUDGET_SECONDS,
            ),
        );
    }

    public function idleReport(): YourStreamWordSweepReportModel
    {
        return YourStreamWordSweepReportModel::none();
    }
}
