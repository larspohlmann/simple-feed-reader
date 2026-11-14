<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class TrendingRebuildReportModel
{
    /** @param list<TrendingBuildReportModel> $builds */
    public function __construct(public array $builds)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function storyCount(): int
    {
        return array_sum(array_map(
            static fn (TrendingBuildReportModel $build): int => $build->storyCount,
            $this->builds,
        ));
    }

    /**
     * @return array{builds: list<array{
     *   window: string, entryCount: int, storyCount: int, durationMs: int, peakMemoryBytes: int
     * }>}
     */
    public function toLogContext(): array
    {
        return [
            'builds' => array_map(
                static fn (TrendingBuildReportModel $build): array => $build->toLogContext(),
                $this->builds,
            ),
        ];
    }
}
