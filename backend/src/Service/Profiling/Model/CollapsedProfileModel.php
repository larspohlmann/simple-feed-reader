<?php

declare(strict_types=1);

namespace App\Service\Profiling\Model;

final readonly class CollapsedProfileModel
{
    public function __construct(
        public string $collapsedStacks,
        public int $sampleCount,
        public int $sampleRateHz,
        public int $startedAtUnix,
        public int $endedAtUnix,
    ) {
    }
}
