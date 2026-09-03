<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What one provider call actually consumed, as the provider accounts for it
 * (#409). Read off the `usage` object OpenAI-compatible endpoints send in the
 * last message of a streamed reply — the only number that is the provider's
 * own, not our guess. Wire bytes are no cost proxy: reasoning and SSE framing
 * inflate them.
 *
 * Cost is nano-credits as an integer because money is never a float. Null
 * means no price reported, the same as "local model, free" — not zero, since
 * zero claims the call was free, a different statement from unpriced.
 */
final readonly class CompletionUsage
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $reasoningTokens,
        public int $cachedTokens,
        public ?int $costNanoCredits,
    ) {
    }
}
