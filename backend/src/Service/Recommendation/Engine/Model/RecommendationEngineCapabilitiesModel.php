<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine\Model;

use App\Enum\RecommendationEngineKind;
use App\Enum\RecommendationProfileSource;

/** What an engine can do, so a client offers only the settings that apply to it. */
final readonly class RecommendationEngineCapabilitiesModel
{
    /** @param list<RecommendationTuningField> $tuningFields */
    public function __construct(
        public bool $writesReasons,
        public bool $sendsPrompt,
        public RecommendationProfileSource $profileSource,
        public array $tuningFields,
    ) {
    }

    /** Per kind, not per engine: reading them builds no engine. */
    public static function of(RecommendationEngineKind $kind): self
    {
        return match ($kind) {
            RecommendationEngineKind::Llm => new self(
                writesReasons: true,
                sendsPrompt: true,
                profileSource: $kind->profileSource(),
                tuningFields: RecommendationTuningField::cases(),
            ),
            RecommendationEngineKind::Scoring => new self(
                writesReasons: false,
                sendsPrompt: false,
                profileSource: $kind->profileSource(),
                tuningFields: [RecommendationTuningField::BatchConcurrency],
            ),
        };
    }
}
