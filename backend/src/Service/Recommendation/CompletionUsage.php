<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What one provider call actually consumed, as the provider itself accounts
 * for it (#409). Read off the `usage` object OpenAI-compatible endpoints send
 * in the last message of a streamed reply — the only number in the whole
 * exchange that is the provider's own, rather than our guess at one. Wire
 * bytes are not a cost proxy: reasoning bytes and SSE framing inflate them.
 *
 * Cost is nano-credits as an integer because it is money, and money is never
 * a float. Null means the provider reported no price at all, which is the
 * same answer as "local model, free" — deliberately not zero, because zero
 * claims the call was free, and that is a different statement from unpriced.
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
