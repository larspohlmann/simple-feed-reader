<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationPromptText;

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
    public static function state(EffectiveRecommendationSettings $effective): array
    {
        return [
            'guidancePrompt' => $effective->guidancePrompt,
            'defaultGuidancePrompt' => RecommendationPromptText::DEFAULT_GUIDANCE,
            'fixedPrompt' => [
                'role' => RecommendationPromptText::SYSTEM_ROLE,
                'outputContract' => RecommendationPromptText::OUTPUT_CONTRACT,
            ],
            'favoritesCap' => $effective->favoritesCap,
            'keptCap' => $effective->keptCap,
            'viewedCap' => $effective->viewedCap,
            'candidatePoolSize' => $effective->candidatePoolSize,
            'picksLimit' => $effective->picksLimit,
            'contextWindow' => $effective->packing->contextWindow,
            'contextWindowOverride' => 'user' === $effective->packing->contextWindowSource
                ? $effective->packing->contextWindow
                : null,
            'contextWindowSource' => $effective->packing->contextWindowSource,
            'batchCount' => $effective->packing->batchCount,
            'debugEnabled' => $effective->debugEnabled,
        ];
    }
}
