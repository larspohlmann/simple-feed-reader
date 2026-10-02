<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine\Model;

/** What an engine can do, so a client offers only the settings that apply to it. */
final readonly class RecommendationEngineCapabilitiesModel
{
    /** @param list<RecommendationTuningField> $tuningFields */
    public function __construct(
        public bool $writesReasons,
        public array $tuningFields,
    ) {
    }
}
