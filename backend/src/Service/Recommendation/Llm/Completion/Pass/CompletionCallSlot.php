<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Completion\Pass;

use App\Service\Ai\Model\ProviderTimeoutsModel;
use App\Service\Recommendation\Llm\Completion\CompletionStreamObserver\CompletionStreamObserverInterface;

/** The per-call state a multiplexed read routes each chunk to: the call's index, reader, observer and bounds. */
final readonly class CompletionCallSlot
{
    public function __construct(
        public int $index,
        public CompletionStreamReader $reader,
        public CompletionStreamObserverInterface $observer,
        public ProviderTimeoutsModel $timeouts,
        /**
         * This call's own `max_tokens`, kept as tokens: the runaway message names them, and dividing a stored byte
         * bound back out would go silently wrong if the derivation changed.
         */
        public int $maximumAnswerTokens,
    ) {
    }
}
