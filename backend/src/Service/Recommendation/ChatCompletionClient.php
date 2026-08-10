<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\ProviderCredentials;

interface ChatCompletionClient
{
    /**
     * One JSON-mode chat completion; returns the assistant message content.
     * Reports the accumulating streamed body to $observer chunk by chunk.
     *
     * @throws CredentialsRejectedException
     * @throws ProviderUnreachableException
     */
    public function complete(
        ProviderCredentials $credentials,
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
    public function completeMany(ProviderCredentials $credentials, array $calls): array;
}
