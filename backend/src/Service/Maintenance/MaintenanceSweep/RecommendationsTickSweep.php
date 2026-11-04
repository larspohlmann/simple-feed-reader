<?php

declare(strict_types=1);

namespace App\Service\Maintenance\MaintenanceSweep;

use App\Service\Recommendation\Run\ForYouSweep;
use App\Service\Recommendation\Run\Model\ForYouSweepReportModel;

final readonly class RecommendationsTickSweep implements MaintenanceSweepInterface
{
    public function __construct(private ForYouSweep $forYouSweep)
    {
    }

    public static function name(): string
    {
        return 'recommendations';
    }

    public static function priority(): int
    {
        return 100;
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
