<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Curation\Recommendation\Stream\InterestTermSweep;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermSweepReportModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'interestTerms', priority: 7)]
final readonly class InterestTermsTickSweep implements MaintenanceSweepInterface
{
    public function __construct(private InterestTermSweep $interestTerms)
    {
    }

    public function run(\DateTimeImmutable $deadline): InterestTermSweepReportModel
    {
        return $this->interestTerms->refreshDueBefore($deadline);
    }

    public function idleReport(): InterestTermSweepReportModel
    {
        return InterestTermSweepReportModel::none();
    }
}
