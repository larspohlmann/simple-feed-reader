<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\AiProviderSettings;

/**
 * The client's view of the account's AI provider configurations, and the ONE
 * definition of "ready".
 *
 * Hand-built for the same reason MeJson is: the entity holds sealed key
 * material, and a serialiser that learned to walk it would put that on the
 * wire. The API key is absent by construction; `apiKeyHint` is the last four
 * characters, which is what lets the settings page say which key is stored.
 *
 * `ready` reports what the last successful save proved — an endpoint, a key
 * and a model the provider accepted together. It is not a live health check:
 * a key revoked since then still reads as ready, and the feature that uses it
 * carries that failure. Polling the provider on every /api/me would be the
 * alternative, and it is not worth a round trip per profile read.
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
            'ready' => self::isReady($settings),
            'active' => $settings->getId() === $activeId,
        ];
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

    /**
     * The one definition of "ready", named so the other responses that report
     * it — MeJson — reach a method instead of an array key no static analysis
     * can follow.
     *
     * No verifiedAt term: AiProviderSettings::chooseModel() is the only writer
     * of `model` and stamps verifiedAt in the same call, while
     * replaceConnection() clears `model` again. A row with a model is therefore
     * always a verified row, so testing both would be a term that can never be
     * false.
     */
    public static function isReady(?AiProviderSettings $settings): bool
    {
        return null !== $settings && $settings->hasModel();
    }
}
