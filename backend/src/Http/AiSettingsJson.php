<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\AiReadiness;
use App\Service\Recommendation\RecommendationPackingSettings;

/**
 * The client's view of the account's AI provider configurations. Hand-built,
 * not serialised, so a sealed key never reaches the wire.
 */
final class AiSettingsJson
{
    /**
     * @return array<string, mixed>
     */
    public static function configuration(AiProviderSettings $settings, ?int $activeId): array
    {
        return [
            'id' => $settings->getId(),
            'name' => $settings->getName(),
            'baseUrl' => $settings->getBaseUrl(),
            'apiKeyHint' => $settings->getApiKeyHint(),
            'model' => $settings->getModel(),
            'suppressReasoning' => $settings->suppressesReasoning(),
            'batchConcurrency' => $settings->batchConcurrency(),
            'slowModel' => $settings->isSlowModel(),
            'maxBatchSize' => $settings->maxBatchSize(),
            'ready' => AiReadiness::of($settings),
            'active' => $settings->getId() === $activeId,
        ];
    }

    /** @return array<string, mixed> */
    public static function configurationFor(AiProviderSettings $settings, User $owner): array
    {
        return self::configuration($settings, $owner->getActiveAiProviderSettings()?->getId());
    }

    /**
     * @param list<AiProviderSettings> $configurations
     *
     * @return array<string, mixed>
     */
    public static function list(array $configurations, ?int $activeId): array
    {
        return [
            'configs' => array_map(
                static fn (AiProviderSettings $each): array => self::configuration($each, $activeId),
                $configurations,
            ),
            'activeId' => $activeId,
            // The ceiling the packer applies when a connection leaves its cap
            // empty. Sent so the settings form shows the same number the run
            // would use, from its one definition rather than a copy that drifts.
            'defaultMaxBatchSize' => RecommendationPackingSettings::DEFAULT_MAXIMUM_BATCH_SIZE,
        ];
    }

    /**
     * @param list<string> $models
     *
     * @return array<string, mixed>
     */
    public static function added(AiProviderSettings $settings, array $models): array
    {
        return self::configuration($settings, null) + ['models' => $models];
    }

    /**
     * @param list<string> $models
     *
     * @return array<string, mixed>
     */
    public static function models(array $models): array
    {
        return ['models' => $models];
    }
}
