<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

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
        $providerWindow = $user->getActiveAiProviderSettings()?->getModelContextWindow();

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
            picksLimit: $row?->values()->picksLimit ?? EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            packing: new RecommendationPackingSettings(
                contextWindow: $window,
                contextWindowSource: $source,
                batchCount: $row?->values()->batchCount,
            ),
            debugEnabled: $row?->values()->debugEnabled ?? false,
            autoGenerateIntervalHours: $row?->values()->autoGenerateIntervalHours,
        );
    }
}
