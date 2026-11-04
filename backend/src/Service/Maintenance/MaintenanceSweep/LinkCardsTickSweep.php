<?php

declare(strict_types=1);

namespace App\Service\Maintenance\MaintenanceSweep;

use App\Service\LinkCard\LinkCardSweep;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\LinkCard\Model\LinkCardSweepReportModel;
use Psr\Clock\ClockInterface;

final readonly class LinkCardsTickSweep implements MaintenanceSweepInterface
{
    public function __construct(
        private LinkCardSweep $linkCardSweep,
        private ClockInterface $clock,
    ) {
    }

    public static function name(): string
    {
        return 'linkCards';
    }

    public static function priority(): int
    {
        return 70;
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
