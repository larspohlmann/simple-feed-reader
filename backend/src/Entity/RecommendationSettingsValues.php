<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecommendationBatchSize;

/**
 * The stored recommendation settings row: every field is an override, so null (or no row) means "use the default".
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") a data carrier that mirrors the row 1:1
 */
final readonly class RecommendationSettingsValues
{
    public function __construct(
        public ?string $guidancePrompt,
        public int $favoritesCap,
        public int $keptCap,
        public int $viewedCap,
        public int $candidatePoolSize,
        public int $lookbackDays,
        public int $picksLimit,
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
