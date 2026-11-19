<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Content\LinkCard\Model\LinkCardDrainReportModel;

final class LinkCardDrainJson
{
    /**
     * @return array{completed: int, caughtUp: bool}
     */
    public static function report(LinkCardDrainReportModel $report): array
    {
        return [
            'completed' => $report->completed,
            'caughtUp' => $report->caughtUp,
        ];
    }
}
