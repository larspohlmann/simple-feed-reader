<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion\Pass;

use App\Service\Ai\Completion\CompletionStreamObserver\CompletionStreamObserverInterface;
use App\Service\Ai\Completion\Model\CompletionRequestModel;

/** One request in a concurrent wave with its own observer, so the multiplexed loop can route each chunk back to it. */
final readonly class ConcurrentCompletion
{
    public function __construct(
        public CompletionRequestModel $request,
        public CompletionStreamObserverInterface $observer,
    ) {
    }
}
