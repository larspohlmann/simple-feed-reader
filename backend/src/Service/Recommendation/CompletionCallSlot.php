<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Ai\ProviderTimeouts;

/**
 * The per-call state a multiplexed read routes each chunk to: which call in
 * the wave this is, its stream parsing state, the observer watching it, and
 * the bounds it was sent under.
 *
 * A value object, not the array it once was, because the timeouts joined it
 * (#433): the reading methods need the first-byte bound to say what a silent
 * provider exceeded, and threading it alongside the slot would have grown
 * every signature from the stream loop to the chunk reader for a value only
 * the last reads.
 */
final readonly class CompletionCallSlot
{
    public function __construct(
        public int $index,
        public CompletionStreamReader $reader,
        public CompletionStreamObserver $observer,
        public ProviderTimeouts $timeouts,
        /**
         * The `max_tokens` this call's own request carried (#437).
         *
         * The token count, not the byte bound derived from it: the client
         * needs both — bytes to guard the reader, tokens to name the ceiling a
         * runaway spent — and storing the product meant dividing it back out,
         * an inverse correct only while the derivation stays a bare
         * multiplication and wrong silently otherwise, inside the diagnosis
         * message. Per call, not per client: a wave's calls can ask for
         * different amounts, and one flat number is the shape that made the old
         * cap unable to fire at all.
         */
        public int $maximumAnswerTokens,
    ) {
    }
}
