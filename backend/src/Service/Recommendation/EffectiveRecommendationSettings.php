<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The recommendation settings a caller actually reads: every override from
 * RecommendationSettingsValues resolved against its default, with the
 * context window additionally resolved against the account's AI provider.
 * RecommendationSettingsResolver is the only producer.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") pure data carrier that
 * mirrors RecommendationSettingsValues field-for-field, not a behavioural
 * method.
 */
final readonly class EffectiveRecommendationSettings
{
    public const int DEFAULT_FAVORITES_CAP = 40;
    public const int DEFAULT_KEPT_CAP = 40;
    public const int DEFAULT_VIEWED_CAP = 80;
    public const int DEFAULT_CANDIDATE_POOL_SIZE = 500;
    public const int DEFAULT_LOOKBACK_DAYS = 2;
    public const int DEFAULT_PICKS_LIMIT = 50;
    public const int FALLBACK_CONTEXT_WINDOW = 32768;

    public function __construct(
        public ?string $guidancePrompt,
        public int $favoritesCap,
        public int $keptCap,
        public int $viewedCap,
        public int $candidatePoolSize,
        /** How many days back the candidate pool reaches, counted as N x 24 h from the snapshot instant. */
        public int $lookbackDays,
        public int $picksLimit,
        public RecommendationPackingSettings $packing,
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
