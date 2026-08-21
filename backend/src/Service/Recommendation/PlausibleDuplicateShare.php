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
 * The rule lives in its own class, named, rather than as a bare comparison
 * inside the consolidation parser: it is one decision -- how much of a pool is
 * plausibly duplicate -- and giving it a name keeps the parser reading as
 * what it does, not how the bound is computed.
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
