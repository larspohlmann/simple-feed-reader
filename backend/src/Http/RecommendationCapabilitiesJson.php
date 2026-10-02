<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\AiProviderSettings;
use App\Service\Recommendation\Engine\Model\RecommendationTuningField;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;

/** What a connection's engine can do, so a client offers only the settings that apply and never names the engine. */
final readonly class RecommendationCapabilitiesJson
{
    public function __construct(private RecommendationEngineResolver $engines)
    {
    }

    /** @return array{reasons: bool, tuningFields: list<string>} */
    public function of(AiProviderSettings $connection): array
    {
        $capabilities = $this->engines->engineFor($connection)->capabilities();

        return [
            'reasons' => $capabilities->writesReasons,
            'tuningFields' => array_map(
                static fn (RecommendationTuningField $field): string => $field->value,
                $capabilities->tuningFields,
            ),
        ];
    }
}
