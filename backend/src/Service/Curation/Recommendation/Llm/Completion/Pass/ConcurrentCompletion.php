<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Completion\Pass;

use App\Service\Recommendation\Llm\Completion\CompletionStreamObserver\CompletionStreamObserverInterface;
use App\Service\Recommendation\Llm\Completion\Model\CompletionRequestModel;

/** One request in a concurrent wave with its own observer, so the multiplexed loop can route each chunk back to it. */
final readonly class ConcurrentCompletion
{
    public function __construct(
        public CompletionRequestModel $request,
        public CompletionStreamObserverInterface $observer,
    ) {
    }
}
