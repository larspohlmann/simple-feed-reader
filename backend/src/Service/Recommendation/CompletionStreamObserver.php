<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Streaming hook for one /chat/completions call (#309): the client reports
 * the growing SSE body after every chunk, and the observer decides what any
 * of it means — throttling, decoding and persistence are its business, so
 * the transport stays dumb.
 */
interface CompletionStreamObserver
{
    /** Called after every received chunk with the whole body accumulated so far. */
    public function bodyGrew(string $accumulatedBody): void;
}
