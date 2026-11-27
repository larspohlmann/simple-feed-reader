<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Curation\Recommendation\Stream\InterestTermSweep;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermSweepReportModel;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'interestTerms', priority: 7)]
final readonly class InterestTermsTickSweep implements MaintenanceSweepInterface
{
    public function __construct(private InterestTermSweep $interestTerms, private ClockInterface $clock)
    {
    }

    public function run(\DateTimeImmutable $deadline): InterestTermSweepReportModel
    {
        return $this->interestTerms->sweep(
            SweepBudgetModel::remainingUntil(
                $deadline,
                $this->clock->now(),
                InterestTermSweep::UNHURRIED_BUDGET_SECONDS,
            ),
        );
    }

    public function idleReport(): InterestTermSweepReportModel
    {
        return InterestTermSweepReportModel::none();
    }
}
