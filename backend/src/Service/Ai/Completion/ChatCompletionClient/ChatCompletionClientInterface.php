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
     * One JSON-mode chat completion, reported to $observer chunk by chunk; returns the assistant content. A reply the
     * model spoiled (a runaway) is returned for the caller's parser to judge; only an endpoint failure throws.
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
     * Several JSON-mode chat completions read in one multiplexed stream, one outcome per call aligned by index. A
     * per-call failure is carried in its outcome, never thrown, so it cannot discard a sibling's answer.
     *
     * @param non-empty-list<ConcurrentCompletion> $calls
     *
     * @return list<CompletionOutcomeModel>
     */
    public function completeMany(ProviderConnectionModel $connection, array $calls): array;
}
