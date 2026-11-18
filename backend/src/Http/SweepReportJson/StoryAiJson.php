<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\Curation\StoryAi\Model\StoryAiReportModel;

final readonly class StoryAiJson implements SweepReportJsonInterface
{
    /** @return array{usable: int, unusable: int, failed: int}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof StoryAiReportModel) {
            return null;
        }

        return $report->toLogContext();
    }
}
