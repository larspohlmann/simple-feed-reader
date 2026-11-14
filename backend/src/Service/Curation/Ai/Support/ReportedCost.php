<?php

declare(strict_types=1);

namespace App\Service\Ai\Support;

final class ReportedCost
{
    /**
     * The price in integer nano-credits; null, not zero (a claim of "free"), when unpriced. A negative, non-finite or
     * out-of-range cost is refused as null, never clamped: it would corrupt the all-time spend or overflow the cast.
     */
    public static function nanoCreditsOf(mixed $cost): ?int
    {
        if (!\is_float($cost) && !\is_int($cost)) {
            return null;
        }

        if ($cost < 0 || !is_finite((float) $cost)) {
            return null;
        }

        $nanoCredits = round((float) $cost * 1_000_000_000);

        // Compared as a float, and with >=, because (float) PHP_INT_MAX rounds
        // up to 2**63 — one past the largest int there is.
        return $nanoCredits >= (float) \PHP_INT_MAX ? null : (int) $nanoCredits;
    }

    private function __construct()
    {
    }
}
