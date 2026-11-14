<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki\Model;

final readonly class LokiSpoolReportModel
{
    public function __construct(
        public int $shipped,
        public int $failed,
    ) {
    }
}
