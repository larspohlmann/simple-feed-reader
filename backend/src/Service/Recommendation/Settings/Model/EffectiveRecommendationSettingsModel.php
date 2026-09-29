<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Settings\Model;

use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;

/**
 * The recommendation settings a caller actually reads: every override from
 * RecommendationSettingsValues resolved against its default, with the
 * context window additionally resolved against the account's AI provider.
 * RecommendationSettingsResolver is the only producer.
 */
final readonly class EffectiveRecommendationSettingsModel
{
    public const int FALLBACK_CONTEXT_WINDOW = 32768;

    public function __construct(
        public ?string $guidancePrompt,
        public RecommendationHistoryCaps $historyCaps,
        public RecommendationPoolLimits $poolLimits,
        public RecommendationPackingSettingsModel $packing,
        public bool $debugEnabled,
        public ?int $autoGenerateIntervalHours = null,
        /**
         * The reader's inferred preference profile (#493), resolved straight
         * from the row with no default of its own — absence just means "none
         * yet". Defaulted to null here only so callers that predate #493 keep
         * compiling.
         */
        public ?string $profileText = null,
        /**
         * Whether the reader wants each pick explained in the UI — the
         * one-line reason and the score beside it, which travel together
         * (#541, widened to the score by #576). Defaulted to false here so
         * callers that predate #541 keep compiling.
         */
        public bool $showReasons = false,
    ) {
    }
}
