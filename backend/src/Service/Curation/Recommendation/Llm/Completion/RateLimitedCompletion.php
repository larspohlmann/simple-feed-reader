<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Llm\Completion;

use App\Service\Curation\Ai\Exception\ProviderRateLimitedException;
use App\Service\Curation\Ai\Model\ProviderConnectionModel;
use App\Service\Curation\Ai\Model\RateLimitedResultModel;
use App\Service\Curation\Ai\Model\RetryPlanModel;
use App\Service\Curation\Ai\RateLimitedCalls;
use App\Service\Curation\Recommendation\Llm\Completion\ChatCompletionClient\ChatCompletionClientInterface;
use App\Service\Curation\Recommendation\Llm\Completion\CompletionStreamObserver\CompletionStreamObserverInterface;
use App\Service\Curation\Recommendation\Llm\Completion\Model\CompletionOutcomeModel;
use App\Service\Curation\Recommendation\Llm\Completion\Model\CompletionRequestModel;
use App\Service\Curation\Recommendation\Llm\Completion\Pass\ConcurrentCompletion;

/**
 * The chat client's calls through the shared rate-limit loop: complete() throws a deferral as
 * ProviderRateLimitedException.
 */
final readonly class RateLimitedCompletion
{
    public function __construct(
        private ChatCompletionClientInterface $chat,
        private RateLimitedCalls $rateLimitedCalls,
    ) {
    }

    public function complete(
        ProviderConnectionModel $connection,
        CompletionRequestModel $request,
        CompletionStreamObserverInterface $observer,
        RetryPlanModel $plan,
    ): string {
        $result = $this->completeMany($connection, [new ConcurrentCompletion($request, $observer)], $plan);

        if ($result->isDeferred()) {
            throw new ProviderRateLimitedException($result->deferSeconds);
        }

        $outcome = $result->outcomes[0];
        if ($outcome->isFailure()) {
            throw $outcome->cause();
        }

        return $outcome->content();
    }

    /**
     * @param non-empty-list<ConcurrentCompletion> $calls
     *
     * @return RateLimitedResultModel<CompletionOutcomeModel>
     */
    public function completeMany(
        ProviderConnectionModel $connection,
        array $calls,
        RetryPlanModel $plan,
    ): RateLimitedResultModel {
        return $this->rateLimitedCalls->send(
            $calls,
            fn (array $subset): array => $this->chat->completeMany($connection, $subset),
            $plan,
        );
    }
}
