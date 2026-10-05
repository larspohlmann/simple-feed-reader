<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\AiProviderSettings;
use App\Enum\RecommendationEngineKind;
use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Engine\Model\RecommendationTuningField;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;

/** What a connection's engine can do, so a client offers only the settings that apply and never names the engine. */
final readonly class RecommendationCapabilitiesJson
{
    public function __construct(private RecommendationEngineResolver $engines)
    {
    }

    /** @return array{reasons: bool, prompt: bool, profile: string, tuningFields: list<string>} */
    public function of(AiProviderSettings $connection): array
    {
        return self::shape($this->engines->capabilitiesFor($connection));
    }

    /** @return array{reasons: bool, prompt: bool, profile: string, tuningFields: list<string>} */
    public function ofKind(RecommendationEngineKind $kind): array
    {
        return self::shape(RecommendationEngineCapabilitiesModel::of($kind));
    }

    /** @return array{reasons: bool, prompt: bool, profile: string, tuningFields: list<string>} */
    private static function shape(RecommendationEngineCapabilitiesModel $capabilities): array
    {
        return [
            'reasons' => $capabilities->writesReasons,
            'prompt' => $capabilities->sendsPrompt,
            'profile' => $capabilities->profileSource->value,
            'tuningFields' => array_map(
                static fn (RecommendationTuningField $field): string => $field->value,
                $capabilities->tuningFields,
            ),
        ];
    }
}
