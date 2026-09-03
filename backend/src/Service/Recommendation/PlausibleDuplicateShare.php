<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * How much of a dedup pool one reply may plausibly call duplicate.
 *
 * The dedup call is shown twice the final list size, so it may name up to half
 * without shortening the final list; above that it claims the reader's whole
 * pool collapses into a handful of stories. In production a reply named 98 of
 * 100 entries and the run completed with four recommendations, no error (#396).
 *
 * The rule is its own named class, not a bare comparison in the consolidation
 * parser: one decision -- how much of a pool is plausibly duplicate -- named so
 * the parser reads as what it does, not how the bound is computed.
 */
final readonly class PlausibleDuplicateShare
{
    private const int PERCENT = 50;

    public static function exceededBy(int $namedCount, int $shownCount): bool
    {
        return $namedCount > self::maximumFor($shownCount);
    }

    private static function maximumFor(int $shownCount): int
    {
        return intdiv($shownCount * self::PERCENT, 100);
    }
}
