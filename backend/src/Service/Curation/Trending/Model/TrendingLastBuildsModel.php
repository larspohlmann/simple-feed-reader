<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Enum\TrendingWindow;

/** When each window of one user was last built, how long that took and how many feeds were active in it. */
final readonly class TrendingLastBuildsModel
{
    /**
     * @param array<string, \DateTimeImmutable> $builtAtByWindow built_at in UTC, by window value
     * @param array<string, int> $durationMillisecondsByWindow by window value
     * @param array<string, int> $activeFeedCountByWindow by window value
     */
    public function __construct(
        private array $builtAtByWindow,
        private array $durationMillisecondsByWindow,
        private array $activeFeedCountByWindow,
    ) {
    }

    public function findBuiltAt(TrendingWindow $window): ?\DateTimeImmutable
    {
        return $this->builtAtByWindow[$window->value] ?? null;
    }

    /** 0 while the window was never built. */
    public function activeFeedCount(TrendingWindow $window): int
    {
        return $this->activeFeedCountByWindow[$window->value] ?? 0;
    }

    /** Null while the window was never built: it is due now. */
    public function computeDueAt(TrendingWindow $window): ?\DateTimeImmutable
    {
        return $this->findBuiltAt($window)?->modify(\sprintf('+%d seconds', $window->rebuildIntervalSeconds()));
    }

    public function computeNextBuildDueAt(): ?\DateTimeImmutable
    {
        $dueTimes = [];
        foreach (TrendingWindow::cases() as $window) {
            $dueAt = $this->computeDueAt($window);
            if ($dueAt === null) {
                return null;
            }
            $dueTimes[] = $dueAt;
        }

        return min($dueTimes);
    }

    /** The last build's duration, or the window's estimate when it was never built. */
    public function expectedDurationMilliseconds(TrendingWindow $window): int
    {
        return $this->durationMillisecondsByWindow[$window->value] ?? $window->firstBuildEstimateMilliseconds();
    }
}
