<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\ProviderConnection;

interface ChatCompletionClient
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
        ProviderConnection $connection,
        CompletionRequest $request,
        CompletionStreamObserver $observer,
    ): string;

    /**
     * Several JSON-mode chat completions at once, read in one multiplexed
     * stream. Returns one CompletionOutcome per call, aligned by index. A
     * per-call transport failure is carried in that call's outcome rather than
     * thrown, so one failed call never discards a sibling's answer (#344).
     *
     * @param non-empty-list<ConcurrentCompletion> $calls
     *
     * @return list<CompletionOutcome>
     */
    public function completeMany(ProviderConnection $connection, array $calls): array;
}
