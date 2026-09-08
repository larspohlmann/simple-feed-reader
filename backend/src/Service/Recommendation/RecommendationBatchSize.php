<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The reader's choice of how large each batch call is packed, expressed as a
 * scale over the connection's automatic per-batch ceiling (#935). The number
 * of batches is never chosen: the packer derives it from the pool and this
 * cap. Medium is the automatic ceiling unchanged, so it reproduces the
 * behaviour that an absent override gave before this became a size choice.
 */
enum RecommendationBatchSize: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';

    /**
     * Candidates per batch for this size, scaled off the connection's
     * automatic ceiling. A larger cap packs bigger batches, so the packer cuts
     * fewer of them; the token budget still applies on top (#935).
     */
    public function batchItemCap(int $automaticCeiling): int
    {
        $percentOfCeiling = match ($this) {
            self::Small => 50,
            self::Medium => 100,
            self::Large => 200,
        };

        return max(1, intdiv($automaticCeiling * $percentOfCeiling, 100));
    }
}
