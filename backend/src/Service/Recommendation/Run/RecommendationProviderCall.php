<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Ai\Completion\CompletionRequest;
use App\Service\Ai\Completion\RateLimitedCompletion;
use App\Service\Ai\Factory\ProviderConnectionFactory;

/**
 * One recorded provider call for the single-call phases (#493). Any failure, an unreadable key included, settles the
 * debug row before it propagates unchanged: an unsettled row reads as "still streaming" forever (#309).
 */
final readonly class RecommendationProviderCall
{
    public function __construct(
        private RateLimitedCompletion $completion,
        private ProviderConnectionFactory $connections,
    ) {
    }

    public function complete(TickContext $tick, CompletionRequest $request, RecordedCall $recordedCall): string
    {
        try {
            return $this->completion->complete(
                $this->connections->forSettings($tick->connection),
                $request,
                $recordedCall,
                $tick->retryPlan(),
            );
        } catch (\Throwable $e) {
            $recordedCall->abortAfterTransportFailure($e->getMessage());

            throw $e;
        }
    }
}
