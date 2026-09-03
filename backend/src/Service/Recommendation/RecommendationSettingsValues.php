<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The stored shape of a per-user recommendation settings row: every field is
 * an override, so `null` (or an absent row, for the caps) means "use the
 * default", not "off". EffectiveRecommendationSettings is what a caller reads.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") pure data carrier that
 * mirrors the settings row 1:1, not a behavioural method.
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
        public ?int $batchCount,
        public bool $debugEnabled,
        public ?int $autoGenerateIntervalHours = null,
        /**
         * The reader's inferred preference profile (#493): read-only through
         * this object's usual callers, written only by
         * RecommendationSettingsWriter::storeProfile(). Defaulted to null so
         * callers that predate #493 keep compiling.
         */
        public ?string $profileText = null,
        /**
         * Whether the reader wants each pick explained in the UI — the
         * one-line reason and the score beside it, which travel together
         * (#541, widened to the score by #576). Defaulted to false so callers
         * that predate #541 keep compiling.
         */
        public bool $showReasons = false,
    ) {
    }
}
