<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Settings;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;
use App\Service\Recommendation\Settings\Model\RecommendationPackingSettingsModel;

/**
 * The settings every recommendation service reads: the user's row over the defaults, and the context window from the
 * row, else the account's AI provider, else the fallback.
 */
final readonly class RecommendationSettingsResolver
{
    public function __construct(
        private RecommendationSettingsRepository $settings,
    ) {
    }

    public function forUser(User $user): EffectiveRecommendationSettingsModel
    {
        $row = $this->settings->findForUser($user);
        $provider = $user->getActiveAiProviderSettings();
        $providerWindow = $provider?->getModelContextWindow();

        [$window, $source] = match (true) {
            null !== $row?->values()->contextWindow => [$row->values()->contextWindow, 'user'],
            null !== $providerWindow => [$providerWindow, 'provider'],
            default => [EffectiveRecommendationSettingsModel::FALLBACK_CONTEXT_WINDOW, 'fallback'],
        };

        return new EffectiveRecommendationSettingsModel(
            guidancePrompt: $row?->values()->guidancePrompt,
            profileText: $row?->values()->profileText,
            historyCaps: $row?->values()->historyCaps ?? RecommendationHistoryCaps::defaults(),
            poolLimits: $row?->values()->poolLimits ?? RecommendationPoolLimits::defaults(),
            packing: new RecommendationPackingSettingsModel(
                contextWindow: $window,
                contextWindowSource: $source,
                batchSize: $row?->values()->batchSize ?? RecommendationBatchSize::Medium,
                maximumBatchSize: self::batchCeilingFor($provider),
            ),
            debugEnabled: $row?->values()->debugEnabled ?? false,
            autoGenerateIntervalHours: $row?->values()->autoGenerateIntervalHours,
            showReasons: $row?->values()->showReasons ?? false,
        );
    }

    /**
     * A property of the connection, not an account setting: what the endpoint can be trusted with. It lives on the
     * connection as configured, so it survives a model change; unset means the default.
     */
    private static function batchCeilingFor(?AiProviderSettings $provider): int
    {
        return $provider?->maxBatchSize() ?? RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE;
    }
}
