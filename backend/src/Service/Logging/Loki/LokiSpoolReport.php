<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

final readonly class LokiSpoolReport
{
    public function __construct(
        public int $shipped,
        public int $failed,
    ) {
    }
}
