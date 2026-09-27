<?php

declare(strict_types=1);

namespace App\Enum;

/** How large each recommendation batch is packed, as a scale over the connection's automatic ceiling. */
enum RecommendationBatchSize: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';

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
