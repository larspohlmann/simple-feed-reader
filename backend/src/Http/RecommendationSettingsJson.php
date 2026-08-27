<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationPromptText;
use App\Service\Recommendation\RecommendationSettingsBounds;

/**
 * The client's view of a user's recommendation settings: the effective
 * values every recommendation service reads, plus the fixed prompt layers
 * the settings card shows as read-only context for the editable guidance.
 *
 * `contextWindowOverride` and `contextWindow` are deliberately distinct:
 * the former is the user's own value, or null when the effective window came
 * from the account's AI provider or the fallback; the latter always carries
 * the effective value. The settings card renders one as an input and the
 * other as a hint.
 */
final class RecommendationSettingsJson
{
    /**
     * @return array<string, mixed>
     */
    public static function state(EffectiveRecommendationSettings $effective, bool $workerAlive): array
    {
        return [
            'guidancePrompt' => $effective->guidancePrompt,
            'profileText' => $effective->profileText,
            'defaultGuidancePrompt' => RecommendationPromptText::DEFAULT_GUIDANCE,
            'fixedPrompt' => [
                'role' => RecommendationPromptText::BATCH_SYSTEM_ROLE,
                'outputContract' => RecommendationPromptText::BATCH_OUTPUT_CONTRACT,
            ],
            'expertDefaults' => [
                'guidancePrompt' => null,
                'favoritesCap' => EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
                'keptCap' => EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
                'viewedCap' => EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
                'candidatePoolSize' => EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
                'picksLimit' => EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
                'batchCount' => null,
                'contextWindow' => null,
            ],
            'expertBounds' => RecommendationSettingsBounds::EXPERT_FIELDS,
            'favoritesCap' => $effective->favoritesCap,
            'keptCap' => $effective->keptCap,
            'viewedCap' => $effective->viewedCap,
            'candidatePoolSize' => $effective->candidatePoolSize,
            'lookbackDays' => $effective->lookbackDays,
            'picksLimit' => $effective->picksLimit,
            'contextWindow' => $effective->packing->contextWindow,
            'contextWindowOverride' => 'user' === $effective->packing->contextWindowSource
                ? $effective->packing->contextWindow
                : null,
            'contextWindowSource' => $effective->packing->contextWindowSource,
            'batchCount' => $effective->packing->batchCount,
            'debugEnabled' => $effective->debugEnabled,
            'showReasons' => $effective->showReasons,
            'autoGenerateIntervalHours' => $effective->autoGenerateIntervalHours,
            'workerAlive' => $workerAlive,
        ];
    }
}
