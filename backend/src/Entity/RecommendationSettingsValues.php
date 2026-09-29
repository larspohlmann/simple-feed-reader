<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecommendationBatchSize;

/**
 * The stored recommendation settings row: every field is an override, so null (or no row) means "use the default".
 */
final readonly class RecommendationSettingsValues
{
    public function __construct(
        public ?string $guidancePrompt,
        public RecommendationHistoryCaps $historyCaps,
        public RecommendationPoolLimits $poolLimits,
        public ?int $contextWindow,
        public RecommendationBatchSize $batchSize,
        public bool $debugEnabled,
        public ?int $autoGenerateIntervalHours = null,
        /** Written only by RecommendationSettingsWriter::storeProfile(); read-only everywhere else. */
        public ?string $profileText = null,
        public bool $showReasons = false,
    ) {
    }
}
