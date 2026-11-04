<?php

declare(strict_types=1);

namespace App\Service\Trending\Model;

use App\Enum\TrendingWindow;

/** When each window's snapshot was last built and how long that took; a window never built has neither. */
final readonly class TrendingLastBuildsModel
{
    /**
     * @param array<string, \DateTimeImmutable> $builtAtByWindow built_at in UTC, by window value
     * @param array<string, int> $durationMillisecondsByWindow by window value
     */
    public function __construct(private array $builtAtByWindow, private array $durationMillisecondsByWindow)
    {
    }

    public function builtAt(TrendingWindow $window): ?\DateTimeImmutable
    {
        return $this->builtAtByWindow[$window->value] ?? null;
    }

    public function durationMilliseconds(TrendingWindow $window): int
    {
        return $this->durationMillisecondsByWindow[$window->value] ?? 0;
    }
}
