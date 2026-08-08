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
 */
final readonly class CompletionStreamProgress
{
    public function __construct(
        public string $answerSoFar,
        public int $wireBytes,
    ) {
    }
}
