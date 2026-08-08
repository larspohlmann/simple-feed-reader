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
     * @param list<array{role: string, content: string}> $messages
     *
     * @throws CredentialsRejectedException
     * @throws ProviderUnreachableException
     */
    public function complete(
        ProviderCredentials $credentials,
        string $model,
        array $messages,
        CompletionStreamObserver $observer,
    ): string;
}
