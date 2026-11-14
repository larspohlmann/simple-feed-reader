<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Content\LinkCard\LinkCardSweep;
use App\Service\Content\LinkCard\Model\LinkCardBudgetModel;
use App\Service\Content\LinkCard\Model\LinkCardSweepReportModel;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'linkCards', priority: 70)]
final readonly class LinkCardsTickSweep implements MaintenanceSweepInterface
{
    public function __construct(
        private LinkCardSweep $linkCardSweep,
        private ClockInterface $clock,
    ) {
    }

    public function run(\DateTimeImmutable $deadline): LinkCardSweepReportModel
    {
        return $this->linkCardSweep->sweep(
            LinkCardBudgetModel::fittingBefore($deadline, $this->clock->now(), LinkCardBudgetModel::largestPass()),
        );
    }

    public function idleReport(): LinkCardSweepReportModel
    {
        return LinkCardSweepReportModel::none();
    }
}
