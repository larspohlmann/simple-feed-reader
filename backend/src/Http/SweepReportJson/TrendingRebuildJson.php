<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\Trending\Model\TrendingRebuildReportModel;

final readonly class TrendingRebuildJson implements SweepReportJsonInterface
{
    /** @return array{windowsRebuilt: int, storiesBuilt: int}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof TrendingRebuildReportModel) {
            return null;
        }

        return ['windowsRebuilt' => \count($report->builds), 'storiesBuilt' => $report->storyCount()];
    }
}
