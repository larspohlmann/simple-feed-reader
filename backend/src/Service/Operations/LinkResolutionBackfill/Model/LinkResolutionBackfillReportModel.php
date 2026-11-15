<?php

declare(strict_types=1);

namespace App\Service\Operations\LinkResolutionBackfill\Model;

use App\Service\Content\LinkResolution\Model\LinkResolutionReportModel;

final readonly class LinkResolutionBackfillReportModel
{
    public function __construct(
        public int $queued,
        public LinkResolutionReportModel $resolutions,
    ) {
    }
}
