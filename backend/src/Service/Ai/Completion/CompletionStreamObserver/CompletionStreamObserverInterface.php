<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion\CompletionStreamObserver;

use App\Service\Ai\Completion\CompletionStreamProgress;

/**
 * Streaming hook for one /chat/completions call (#309): the client reports
 * progress after every chunk, and the observer decides what any of it means
 * — throttling and persistence are its business, so the transport stays dumb.
 */
interface CompletionStreamObserverInterface
{
    /** Called after every received chunk, with the answer decoded so far. */
    public function streamProgressed(CompletionStreamProgress $progress): void;
}
