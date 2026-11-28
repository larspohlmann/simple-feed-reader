<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

use App\Enum\YourStreamPrecision;

final readonly class YourStreamExplanationModel
{
    /**
     * @param list<StreamWordModel> $words
     * @param array<string, int>    $matchesByPrecision precision value => matches
     */
    public function __construct(
        public array $words,
        public int $days,
        public int $entryCount,
        public array $matchesByPrecision,
    ) {
    }

    public function countMatches(YourStreamPrecision $precision): int
    {
        return $this->matchesByPrecision[$precision->value] ?? 0;
    }

    public function matchesPerDay(YourStreamPrecision $precision): float
    {
        return $this->countMatches($precision) / $this->days;
    }
}
