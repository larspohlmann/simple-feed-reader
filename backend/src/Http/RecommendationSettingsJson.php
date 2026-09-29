<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\RecommendationSettings;
use App\Enum\RecommendationBatchSize;
use App\Service\Recommendation\Prompt\Support\RecommendationPromptText;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;
use App\Service\Recommendation\Settings\Support\RecommendationSettingsBounds;

/**
 * The effective recommendation settings plus the fixed prompt layers the card shows read-only. `contextWindowOverride`
 * is the user's own value or null; `contextWindow` is always the effective one.
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
            'favoritesCap' => $effective->historyCaps->favorites,
            'keptCap' => $effective->historyCaps->kept,
            'viewedCap' => $effective->historyCaps->viewed,
            'candidatePoolSize' => $effective->poolLimits->candidatePoolSize,
            'lookbackDays' => $effective->poolLimits->lookbackDays,
            'picksLimit' => $effective->poolLimits->picksLimit,
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
