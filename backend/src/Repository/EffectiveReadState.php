<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The single definition of "effective hidden": an explicit per-entry flag wins;
 * absent one, the subscription's mark-all-read watermark hides everything at
 * or below it. Extracted from EntryListRepository::rowIsHidden so the for-you feed
 * projection in RecommendationItemRepository can fold the same rule without
 * duplicating it.
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
