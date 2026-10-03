<?php

declare(strict_types=1);

namespace App\Enum;

/** Which engine turns a connection's runs into a list; the value keys the engine in the resolver's locator. */
enum RecommendationEngineKind: string
{
    case Llm = 'llm';
    case Jev = 'jev';

    /** @return list<CallPhase> the phases a run of this kind calls the provider in, in order */
    public function phases(): array
    {
        return match ($this) {
            self::Llm => [CallPhase::Batch, CallPhase::Consolidate],
            self::Jev => [CallPhase::Batch],
        };
    }

    public function runs(CallPhase $phase): bool
    {
        return \in_array($phase, $this->phases(), true);
    }

    /** The single calls around the batches, which a run's progress counts like batches. */
    public function singleCallPhaseCount(): int
    {
        return \count(array_filter(
            $this->phases(),
            static fn (CallPhase $phase): bool => CallPhase::Batch !== $phase,
        ));
    }
}
