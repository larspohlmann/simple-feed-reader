<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

use App\Enum\YourStreamPrecision;

final readonly class YourStreamExplanationModel
{
    public const string WEIGHT_TIMES_RARITY = 'weightTimesRarity';
    public const string FLAT_RARITY = 'flatRarity';

    /**
     * @param list<StreamWordModel>             $words
     * @param array<string, array<string, int>> $matchesByVariantAndPrecision variant => precision value => matches
     */
    public function __construct(
        public array $words,
        public int $days,
        public int $entryCount,
        public array $matchesByVariantAndPrecision,
    ) {
    }

    public function matchesPerDay(string $variant, YourStreamPrecision $precision): float
    {
        return ($this->matchesByVariantAndPrecision[$variant][$precision->value] ?? 0) / $this->days;
    }
}
