<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * One request in a concurrent wave, paired with the observer that watches its
 * own stream. completeMany() reads several of these at once, so each call has
 * to carry its observer with it — the multiplexed loop routes every chunk back
 * to the observer of the response it belongs to (#344).
 */
final readonly class ConcurrentCompletion
{
    public function __construct(
        public CompletionRequest $request,
        public CompletionStreamObserver $observer,
    ) {
    }
}
