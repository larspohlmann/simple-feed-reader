<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The one definition of "effectively hidden": an explicit per-entry flag wins; without one, the subscription's
 * mark-all-read watermark hides everything at or below it.
 */
final class EffectiveReadState
{
    public static function isHidden(
        ?bool $explicitFlag,
        ?\DateTimeInterface $markedReadUntil,
        \DateTimeImmutable $effectiveDate,
    ): bool {
        if ($explicitFlag !== null) {
            return $explicitFlag;
        }

        return $markedReadUntil !== null && $effectiveDate <= $markedReadUntil;
    }
}
