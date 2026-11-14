<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Curation\Recommendation\Run\ForYouSweep;
use App\Service\Curation\Recommendation\Run\Model\ForYouSweepReportModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'recommendations', priority: 100)]
final readonly class RecommendationsTickSweep implements MaintenanceSweepInterface
{
    public function __construct(private ForYouSweep $forYouSweep)
    {
    }

    public function run(\DateTimeImmutable $deadline): ForYouSweepReportModel
    {
        return $this->forYouSweep->sweepOnce();
    }

    public function idleReport(): ForYouSweepReportModel
    {
        return ForYouSweepReportModel::none();
    }
}
