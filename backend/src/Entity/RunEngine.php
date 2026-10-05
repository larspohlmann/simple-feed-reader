<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use Doctrine\ORM\Mapping as ORM;

/** The engine a run's frozen plan was packed for, and for a scoring model the protocol it speaks. */
#[ORM\Embeddable]
final class RunEngine
{
    #[ORM\Column(length: 16, nullable: true, enumType: RecommendationEngineKind::class)]
    private ?RecommendationEngineKind $engineKind = null;

    #[ORM\Column(length: 16, nullable: true, enumType: ScoringProtocol::class)]
    private ?ScoringProtocol $scoringProtocol = null;

    public function record(RecommendationEngineKind $engineKind, ?ScoringProtocol $scoringProtocol): void
    {
        $this->engineKind = $engineKind;
        $this->scoringProtocol = $scoringProtocol;
    }

    /** A run without a recorded kind predates the column and ran on the LLM, the only engine there was. */
    public function getEngineKind(): RecommendationEngineKind
    {
        return $this->engineKind ?? RecommendationEngineKind::Llm;
    }

    public function getScoringProtocol(): ?ScoringProtocol
    {
        return $this->scoringProtocol;
    }
}
