<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion\Model;

/**
 * What one call consumed, by the provider's own `usage` report; wire bytes are no cost proxy. Cost is integer
 * nano-credits; null means unpriced, which differs from zero, a claim that the call was free.
 */
final readonly class CompletionUsageModel
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
