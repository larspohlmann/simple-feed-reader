<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Prompt;

/**
 * A dedup reply may name at most half its pool: the pool is twice the final list, so more would shorten it. In
 * production a reply named 98 of 100 entries and the run completed with four recommendations, no error (#396).
 */
final readonly class PlausibleDuplicateShare
{
    private const int PERCENT = 50;

    public function exceededBy(int $namedCount, int $shownCount): bool
    {
        return $namedCount > self::maximumFor($shownCount);
    }

    private static function maximumFor(int $shownCount): int
    {
        return intdiv($shownCount * self::PERCENT, 100);
    }
}
