<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;

/**
 * One model as the provider's catalog describes it, and what a connection stores once it is chosen. The context window
 * is null when the provider does not report one — most OpenAI-style gateways do (context_length or
 * max_context_length), OpenAI itself does not.
 */
final readonly class ModelDescriptor
{
    public function __construct(
        public string $id,
        public ?int $contextWindow,
        public ?ScoringProtocol $scoringProtocol = null,
    ) {
        if (null !== $scoringProtocol && (null === $contextWindow || $contextWindow <= 0)) {
            throw new \InvalidArgumentException(
                sprintf('The scoring model "%s" needs a positive context window.', $id),
            );
        }
    }

    public function kind(): RecommendationEngineKind
    {
        return null === $this->scoringProtocol ? RecommendationEngineKind::Llm : RecommendationEngineKind::Scoring;
    }
}
