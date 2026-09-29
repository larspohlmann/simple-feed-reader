<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Ai\Completion\Model\CompletionRequestModel;
use App\Service\Ai\Completion\RateLimitedCompletion;
use App\Service\Ai\Factory\ProviderConnectionFactory;
use App\Service\Recommendation\Run\Pass\RecordedCall;
use App\Service\Recommendation\Run\Pass\TickContext;

/**
 * One recorded provider call for the single-call phases. Any failure, an unreadable key included, settles the debug
 * row before it propagates unchanged: an unsettled row reads as "still streaming" forever.
 */
final readonly class RecommendationProviderCall
{
    public function __construct(
        private RateLimitedCompletion $completion,
        private ProviderConnectionFactory $connectionFactory,
    ) {
    }

    public function complete(TickContext $tick, CompletionRequestModel $request, RecordedCall $recordedCall): string
    {
        try {
            return $this->completion->complete(
                $this->connectionFactory->forSettings($tick->connection),
                $request,
                $recordedCall,
                $tick->retryPlan(),
            );
        } catch (\Throwable $exception) {
            $recordedCall->abortAfterTransportFailure($exception->getMessage());

            throw $exception;
        }
    }
}
