<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Content\LinkResolution\LinkResolutionSweep;
use App\Service\Content\LinkResolution\Model\LinkResolutionBudgetModel;
use App\Service\Content\LinkResolution\Model\LinkResolutionReportModel;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'linkResolutions', priority: 65)]
final readonly class LinkResolutionsTickSweep implements MaintenanceSweepInterface
{
    public function __construct(
        private LinkResolutionSweep $linkResolutionSweep,
        private ClockInterface $clock,
    ) {
    }

    public function run(\DateTimeImmutable $deadline): LinkResolutionReportModel
    {
        return $this->linkResolutionSweep->resolvePending(
            LinkResolutionBudgetModel::fittingBefore($deadline, $this->clock->now()),
        );
    }

    public function idleReport(): LinkResolutionReportModel
    {
        return LinkResolutionReportModel::none();
    }
}
