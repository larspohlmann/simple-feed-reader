<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Enum\TrendingWindow;

final readonly class TrendingBuildReportModel
{
    public function __construct(
        public TrendingWindow $window,
        public \DateTimeImmutable $builtAt,
        public int $entryCount,
        public int $storyCount,
        public int $durationMilliseconds,
        public int $peakMemoryBytes,
    ) {
    }

    /** @return array{window: string, entryCount: int, storyCount: int, durationMs: int, peakMemoryBytes: int} */
    public function toLogContext(): array
    {
        return [
            'window' => $this->window->value,
            'entryCount' => $this->entryCount,
            'storyCount' => $this->storyCount,
            'durationMs' => $this->durationMilliseconds,
            'peakMemoryBytes' => $this->peakMemoryBytes,
        ];
    }
}
