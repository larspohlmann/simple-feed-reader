<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * One report of a streamed provider call's progress.
 *
 * The two numbers say different things and the debug view (#309) needs both:
 * `answerSoFar` is what the model has actually answered, `wireBytes` is what
 * it has sent. A reasoning model spends megabytes of the second while the
 * first stays empty, and without the second that call looks identical to a
 * provider that said nothing at all (#320).
 *
 * `finishReason` is the provider's own account of why it stopped — `length`
 * telling the debug view that `max_tokens` truncated the answer, which is the
 * difference between a starved reasoning model and a mute provider (#327).
 * Null until the provider stamps it.
 *
 * `usage` is the provider's own accounting for the call — tokens and price
 * (#409). It rides here rather than being threaded through the client, the
 * advancer and the wave as a new parameter: this object already travels from
 * the transport to the observer, and a value with no home is what phptramp
 * exists to catch. Null until the provider sends its usage message, and for a
 * provider that never sends one.
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
