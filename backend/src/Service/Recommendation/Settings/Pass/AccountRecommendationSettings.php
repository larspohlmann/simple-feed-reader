<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Settings\Pass;

use App\Entity\AiProviderSettings;
use App\Entity\ProfileSettingsValues;
use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Enum\RecommendationBatchSize;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;
use App\Service\Recommendation\Settings\Model\RecommendationPackingSettingsModel;

/**
 * The account's row over the defaults, resolved against any of its connections: the context window from the row, else
 * the connection, else the fallback.
 */
final readonly class AccountRecommendationSettings
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(private ?RecommendationSettings $row)
    {
    }

    public function forConnection(?AiProviderSettings $provider): EffectiveRecommendationSettingsModel
    {
        $values = $this->row?->values();
        $providerWindow = $provider?->getModelContextWindow();

        [$window, $source] = match (true) {
            null !== $values?->contextWindow => [$values->contextWindow, 'user'],
            null !== $providerWindow => [$providerWindow, 'provider'],
            default => [EffectiveRecommendationSettingsModel::FALLBACK_CONTEXT_WINDOW, 'fallback'],
        };

        return new EffectiveRecommendationSettingsModel(
            guidancePrompt: $values?->guidancePrompt,
            historyCaps: $this->historyCaps($values),
            poolLimits: $values->poolLimits ?? RecommendationPoolLimits::defaults(),
            packing: new RecommendationPackingSettingsModel(
                contextWindow: $window,
                contextWindowSource: $source,
                batchSize: $values->batchSize ?? RecommendationBatchSize::Medium,
                maximumBatchSize: self::batchCeilingFor($provider),
            ),
            debugEnabled: $values->debugEnabled ?? false,
            autoGenerateIntervalHours: $values?->autoGenerateIntervalHours,
            showScoreAndReasons: $values->showScoreAndReasons ?? false,
        );
    }

    /** The favorites cap is the recommendation form's; kept and viewed are the profile section's. */
    private function historyCaps(?RecommendationSettingsValues $values): RecommendationHistoryCaps
    {
        $profileSettings = $this->row?->profileSettings() ?? ProfileSettingsValues::defaults();

        return new RecommendationHistoryCaps(
            $values->favoritesCap ?? RecommendationSettings::DEFAULT_FAVORITES_CAP,
            $profileSettings->keptCap,
            $profileSettings->viewedCap,
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
