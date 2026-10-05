<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Support\AiReadiness;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Settings\Model\RecommendationPackingSettingsModel;

/**
 * The client's view of the account's AI provider configurations. Hand-built,
 * not serialised, so a sealed key never reaches the wire.
 */
final readonly class AiSettingsJson
{
    public function __construct(
        private RecommendationCapabilitiesJson $capabilities,
        private RecommendationEngineResolver $engines,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function configuration(AiProviderSettings $settings, ?int $activeId): array
    {
        return [
            'id' => $settings->getId(),
            'name' => $settings->getName(),
            'baseUrl' => $settings->getBaseUrl(),
            'apiKeyHint' => $settings->getApiKeyHint(),
            'model' => $settings->getModel(),
            'suppressReasoning' => $settings->suppressesReasoning(),
            'suppressionRefused' => $settings->refusesSuppressedReasoning(),
            'batchConcurrency' => $settings->getRunTuning()->batchConcurrency(),
            'slowModel' => $settings->isSlowModel(),
            'maxBatchSize' => $settings->getRunTuning()->maxBatchSize(),
            'ready' => AiReadiness::of($settings),
            'active' => $settings->getId() === $activeId,
            'capabilities' => $this->capabilities->of($settings),
        ];
    }

    /** @return array<string, mixed> */
    public function configurationFor(AiProviderSettings $settings, User $owner): array
    {
        return $this->configuration($settings, $owner->getActiveAiProviderSettings()?->getId());
    }

    /**
     * @param list<AiProviderSettings> $configurations
     *
     * @return array<string, mixed>
     */
    public function list(array $configurations, ?int $activeId): array
    {
        return [
            'configs' => array_map(
                fn (AiProviderSettings $each): array => $this->configuration($each, $activeId),
                $configurations,
            ),
            'activeId' => $activeId,
            // The ceiling the packer applies when a connection leaves its cap
            // empty. Sent so the settings form shows the same number the run
            // would use, from its one definition rather than a copy that drifts.
            'defaultMaxBatchSize' => RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
        ];
    }

    /**
     * @param list<string> $models
     *
     * @return array<string, mixed>
     */
    public function added(AiProviderSettings $settings, array $models): array
    {
        return $this->configuration($settings, null) + $this->models($models);
    }

    /**
     * @param list<string> $models
     *
     * @return array{models: list<array{id: string, label: ?string, capabilities: array<string, mixed>}>}
     */
    public function models(array $models): array
    {
        return [
            'models' => array_map(
                fn (string $model): array => [
                    'id' => $model,
                    'label' => $this->engines->labelForModel($model),
                    'capabilities' => $this->capabilities->ofModel($model),
                ],
                $models,
            ),
        ];
    }
}
