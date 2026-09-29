<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion\ChatCompletionClient;

use App\Service\Ai\Completion\CompletionStreamObserver\CompletionStreamObserverInterface;
use App\Service\Ai\Completion\Model\CompletionOutcomeModel;
use App\Service\Ai\Completion\Model\CompletionRequestModel;
use App\Service\Ai\Completion\Pass\ConcurrentCompletion;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ProviderConnectionModel;

interface ChatCompletionClientInterface
{
    /**
     * One JSON-mode chat completion; returns the assistant message content.
     * Reports the accumulating streamed body to $observer chunk by chunk.
     *
     * A reply the endpoint delivered and the model spoiled — a runaway, say —
     * is returned, not thrown: it is content the caller's parser judges and
     * retries against, and only a failure of the endpoint itself is an
     * exception here (#437).
     *
     * @throws CredentialsRejectedException
     * @throws ProviderUnreachableException
     */
    public function complete(
        ProviderConnectionModel $connection,
        CompletionRequestModel $request,
        CompletionStreamObserverInterface $observer,
    ): string;

    /**
     * Several JSON-mode chat completions at once, read in one multiplexed
     * stream. Returns one CompletionOutcomeModel per call, aligned by index. A
     * per-call transport failure is carried in that call's outcome rather than
     * thrown, so one failed call never discards a sibling's answer (#344).
     *
     * @param non-empty-list<ConcurrentCompletion> $calls
     *
     * @return list<CompletionOutcomeModel>
     */
    public function completeMany(ProviderConnectionModel $connection, array $calls): array;
}
