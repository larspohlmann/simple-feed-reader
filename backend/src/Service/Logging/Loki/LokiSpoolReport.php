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

    /**
     * @return array{shipped: int, failed: int}
     */
    public function toArray(): array
    {
        return ['shipped' => $this->shipped, 'failed' => $this->failed];
    }
}
