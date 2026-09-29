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
 * Combines the per-user override row (if any) with the account's active AI
 * configuration's context window into the settings every recommendation
 * service reads, applying the caps' and window's fallback defaults in one
 * place.
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
     * How many candidates one batch may carry. Read off the connection, not
     * offered as a setting, because it describes what the endpoint can be
     * trusted with, not what the account likes (#437). The connection as
     * configured carries it, not the model behind it, so the column survives a
     * model change. No claim means the default. Split off `slow_model` in #445,
     * which now governs timeouts alone.
     */
    private static function batchCeilingFor(?AiProviderSettings $provider): int
    {
        return $provider?->maxBatchSize() ?? RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE;
    }
}
