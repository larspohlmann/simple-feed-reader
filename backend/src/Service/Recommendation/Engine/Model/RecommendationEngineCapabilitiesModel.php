<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine\Model;

use App\Enum\RecommendationEngineKind;

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
                profileSource: RecommendationProfileSource::Own,
                tuningFields: RecommendationTuningField::cases(),
            ),
            RecommendationEngineKind::Jev => new self(
                writesReasons: false,
                sendsPrompt: false,
                profileSource: RecommendationProfileSource::Borrowed,
                tuningFields: [RecommendationTuningField::BatchConcurrency],
            ),
        };
    }
}
