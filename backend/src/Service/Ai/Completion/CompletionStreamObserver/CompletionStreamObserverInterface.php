<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion\CompletionStreamObserver;

use App\Service\Ai\Completion\Model\CompletionStreamProgressModel;

/**
 * Streaming hook for one /chat/completions call: the client reports
 * progress after every chunk, and the observer decides what any of it means
 * — throttling and persistence are its business, so the transport stays dumb.
 */
interface CompletionStreamObserverInterface
{
    /** Called after every received chunk, with the answer decoded so far. */
    public function streamProgressed(CompletionStreamProgressModel $progress): void;
}
