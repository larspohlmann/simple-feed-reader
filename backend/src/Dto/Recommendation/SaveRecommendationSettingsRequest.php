<?php

declare(strict_types=1);

namespace App\Dto\Recommendation;

use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Recommendation\RecommendationSettingsBounds;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The client's write shape for a user's recommendation settings row, a 1:1
 * mirror of RecommendationSettingsValues. Blank-guidance normalisation is
 * deliberately not here: RecommendationSettingsWriter owns that decision, not
 * the wire format.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") pure data carrier that
 * mirrors RecommendationSettingsValues field-for-field, not a behavioural
 * method.
 */
final readonly class SaveRecommendationSettingsRequest
{
    public function __construct(
        #[Assert\Length(max: 4000)]
        public ?string $guidancePrompt,
        #[Assert\Range(
            min: RecommendationSettingsBounds::FAVORITES_CAP_MINIMUM,
            max: RecommendationSettingsBounds::FAVORITES_CAP_MAXIMUM,
        )]
        public int $favoritesCap,
        #[Assert\Range(
            min: RecommendationSettingsBounds::KEPT_CAP_MINIMUM,
            max: RecommendationSettingsBounds::KEPT_CAP_MAXIMUM,
        )]
        public int $keptCap,
        #[Assert\Range(
            min: RecommendationSettingsBounds::VIEWED_CAP_MINIMUM,
            max: RecommendationSettingsBounds::VIEWED_CAP_MAXIMUM,
        )]
        public int $viewedCap,
        #[Assert\Range(
            min: RecommendationSettingsBounds::CANDIDATE_POOL_SIZE_MINIMUM,
            max: RecommendationSettingsBounds::CANDIDATE_POOL_SIZE_MAXIMUM,
        )]
        public int $candidatePoolSize,
        #[Assert\Range(min: 1, max: 7)]
        public int $lookbackDays,
        #[Assert\Range(
            min: RecommendationSettingsBounds::PICKS_LIMIT_MINIMUM,
            max: RecommendationSettingsBounds::PICKS_LIMIT_MAXIMUM,
        )]
        public int $picksLimit,
        #[Assert\Range(
            min: RecommendationSettingsBounds::CONTEXT_WINDOW_MINIMUM,
            max: RecommendationSettingsBounds::CONTEXT_WINDOW_MAXIMUM,
        )]
        public ?int $contextWindow,
        #[Assert\Range(
            min: RecommendationSettingsBounds::BATCH_COUNT_MINIMUM,
            max: RecommendationSettingsBounds::BATCH_COUNT_MAXIMUM,
        )]
        public ?int $batchCount,
        public bool $debugEnabled,
        #[Assert\Choice(choices: [null, 1, 3, 6, 12, 24])]
        public ?int $autoGenerateIntervalHours,
        public bool $showReasons = false,
    ) {
    }

    public function values(): RecommendationSettingsValues
    {
        return new RecommendationSettingsValues(
            guidancePrompt: $this->guidancePrompt,
            favoritesCap: $this->favoritesCap,
            keptCap: $this->keptCap,
            viewedCap: $this->viewedCap,
            candidatePoolSize: $this->candidatePoolSize,
            lookbackDays: $this->lookbackDays,
            picksLimit: $this->picksLimit,
            contextWindow: $this->contextWindow,
            batchCount: $this->batchCount,
            debugEnabled: $this->debugEnabled,
            autoGenerateIntervalHours: $this->autoGenerateIntervalHours,
            showReasons: $this->showReasons,
        );
    }
}
