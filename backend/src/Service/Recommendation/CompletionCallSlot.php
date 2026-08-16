<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Ai\ProviderTimeouts;

/**
 * The per-call state a multiplexed read routes each chunk to: which call in
 * the wave this is, the parsing state for its stream, the observer watching
 * it, and the bounds it was sent under.
 *
 * A value object rather than the array shape it used to be, because the
 * timeouts joined it (#433): the reading methods need the first-byte bound to
 * say what a silent provider exceeded, and threading it alongside the slot
 * would have grown every signature between the stream loop and the chunk
 * reader for a value only the last of them reads.
 */
final readonly class CompletionCallSlot
{
    public function __construct(
        public int $index,
        public CompletionStreamReader $reader,
        public CompletionStreamObserver $observer,
        public ProviderTimeouts $timeouts,
        /**
         * How much answer this call may retain before it is a runaway, derived
         * from the `max_tokens` its own request carried (#437). Per call rather
         * than per client: a wave's calls can ask for different amounts, and
         * one flat number for all of them is the shape that made the old cap
         * unable to fire.
         */
        public int $maximumAnswerBytes,
    ) {
    }
}
