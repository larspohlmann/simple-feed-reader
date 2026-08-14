<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * How much of a dedup pool one reply may plausibly call duplicate.
 *
 * The dedup call is shown twice the final list size, so it may name up to
 * half of it without shortening the final list at all; above that it is
 * claiming the reader's whole pool collapses into a handful of stories. In
 * production a reply named 98 of the 100 entries it was shown -- complete,
 * well-formed, and answered in one go -- and the run completed with four
 * recommendations and no error (#396).
 *
 * The rule lives here rather than in either of its two users because both
 * must say the same number: the prompt tells the model the bound, and the
 * parser holds the model to it. A bound the model is never told is a trap,
 * and two numbers that drift apart are worse than none.
 */
final readonly class PlausibleDuplicateShare
{
    private const int PERCENT = 50;

    public static function maximumFor(int $shownCount): int
    {
        return intdiv($shownCount * self::PERCENT, 100);
    }

    public static function exceededBy(int $namedCount, int $shownCount): bool
    {
        return $namedCount > self::maximumFor($shownCount);
    }
}
