<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Repository\RecommendationSettingsRepository;

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

    public function forUser(User $user): EffectiveRecommendationSettings
    {
        $row = $this->settings->findForUser($user);
        $provider = $user->getActiveAiProviderSettings();
        $providerWindow = $provider?->getModelContextWindow();

        [$window, $source] = match (true) {
            null !== $row?->values()->contextWindow => [$row->values()->contextWindow, 'user'],
            null !== $providerWindow => [$providerWindow, 'provider'],
            default => [EffectiveRecommendationSettings::FALLBACK_CONTEXT_WINDOW, 'fallback'],
        };

        return new EffectiveRecommendationSettings(
            guidancePrompt: $row?->values()->guidancePrompt,
            favoritesCap: $row?->values()->favoritesCap ?? EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: $row?->values()->keptCap ?? EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: $row?->values()->viewedCap ?? EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: $row?->values()->candidatePoolSize
                ?? EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            lookbackDays: $row?->values()->lookbackDays
                ?? EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS,
            picksLimit: $row?->values()->picksLimit ?? EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            packing: new RecommendationPackingSettings(
                contextWindow: $window,
                contextWindowSource: $source,
                batchCount: $row?->values()->batchCount,
                maximumBatchSize: self::batchCeilingFor($provider),
            ),
            debugEnabled: $row?->values()->debugEnabled ?? false,
            autoGenerateIntervalHours: $row?->values()->autoGenerateIntervalHours,
        );
    }

    /**
     * How many candidates one batch may carry. A property of the endpoint, not
     * of the account's taste, so it is read off the connection rather than
     * offered as a setting: a small local model holds a shorter list in order
     * than a hosted one, and the account already says which it has by marking
     * the connection slow (#437). No configuration means no claim either way,
     * and the default stands.
     */
    private static function batchCeilingFor(?AiProviderSettings $provider): int
    {
        if ($provider?->isSlowModel() ?? false) {
            return RecommendationPackingSettings::SLOW_MODEL_MAXIMUM_BATCH_SIZE;
        }

        return RecommendationPackingSettings::DEFAULT_MAXIMUM_BATCH_SIZE;
    }
}
