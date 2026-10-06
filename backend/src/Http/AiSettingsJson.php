<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\User;
use App\Enum\ScoringProtocol;
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
            'kind' => $this->engines->kindFor($settings)->value,
            'family' => self::familyOf($settings->getScoringProtocol()),
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
     * @param list<ModelDescriptor> $models
     *
     * @return array<string, mixed>
     */
    public function added(AiProviderSettings $settings, array $models): array
    {
        return $this->configuration($settings, null) + $this->models($models);
    }

    /**
     * @param list<ModelDescriptor> $models
     *
     * @return array{models: list<array{
     *     id: string, label: ?string, kind: string, family: ?string, capabilities: array<string, mixed>
     * }>}
     */
    public function models(array $models): array
    {
        return [
            'models' => array_map(
                fn (ModelDescriptor $model): array => [
                    'id' => $model->id,
                    'label' => self::labelOf($model->scoringProtocol),
                    'kind' => $model->kind()->value,
                    'family' => self::familyOf($model->scoringProtocol),
                    'capabilities' => $this->capabilities->ofKind($model->kind()),
                ],
                $models,
            ),
        ];
    }

    private static function labelOf(?ScoringProtocol $protocol): ?string
    {
        return match ($protocol) {
            ScoringProtocol::SystemOne => 'System One',
            ScoringProtocol::Rerank => 'Rerank',
            null => null,
        };
    }

    private static function familyOf(?ScoringProtocol $protocol): ?string
    {
        return $protocol?->family()->value;
    }
}
