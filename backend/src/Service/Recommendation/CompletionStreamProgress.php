<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * One report of a streamed provider call's progress.
 *
 * The two numbers differ and the debug view (#309) needs both: `answerSoFar`
 * is what the model has answered, `wireBytes` what it has sent. A reasoning
 * model spends megabytes of the second while the first stays empty; without
 * it, that call looks identical to a mute provider (#320).
 *
 * `finishReason` is the provider's account of why it stopped — `length` tells
 * the debug view `max_tokens` truncated the answer, the difference between a
 * starved reasoning model and a mute provider (#327). Null until stamped.
 *
 * `usage` is the provider's own accounting — tokens and price (#409). It rides
 * here rather than being threaded through the client, advancer and wave as a
 * parameter: this object already travels transport-to-observer, and a value
 * with no home is what phptramp catches. Null until the usage message, and for
 * a provider that never sends one.
 */
final readonly class CompletionStreamProgress
{
    public function __construct(
        public string $answerSoFar,
        public int $wireBytes,
        public ?string $finishReason = null,
        public ?CompletionUsage $usage = null,
    ) {
    }
}
