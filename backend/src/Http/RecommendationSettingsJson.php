<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\RecommendationSettings;
use App\Enum\RecommendationBatchSize;
use App\Service\Recommendation\Prompt\Support\RecommendationPromptText;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;
use App\Service\Recommendation\Settings\Support\RecommendationSettingsBounds;

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
    public static function state(EffectiveRecommendationSettingsModel $effective, bool $workerAlive): array
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
                'favoritesCap' => RecommendationSettings::DEFAULT_FAVORITES_CAP,
                'keptCap' => RecommendationSettings::DEFAULT_KEPT_CAP,
                'viewedCap' => RecommendationSettings::DEFAULT_VIEWED_CAP,
                'candidatePoolSize' => RecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
                'picksLimit' => RecommendationSettings::DEFAULT_PICKS_LIMIT,
                'batchSize' => RecommendationBatchSize::Medium->value,
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
            'batchSize' => $effective->packing->batchSize->value,
            'debugEnabled' => $effective->debugEnabled,
            'showReasons' => $effective->showReasons,
            'autoGenerateIntervalHours' => $effective->autoGenerateIntervalHours,
            'workerAlive' => $workerAlive,
        ];
    }
}
