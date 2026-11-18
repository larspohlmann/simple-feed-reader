<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi;

use App\Service\Curation\Ai\Exception\AiKeyUnreadableException;
use App\Service\Curation\Ai\Exception\CredentialsRejectedException;
use App\Service\Curation\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Curation\Ai\Exception\ProviderUnreachableException;
use App\Service\Curation\Ai\Exception\RetryableProviderException;
use App\Service\Curation\Ai\Model\RetryPlanModel;
use App\Service\Curation\Recommendation\Llm\Prompt\Factory\RecommendationCompletionRequestFactory;
use App\Service\Curation\Recommendation\Llm\Prompt\Model\CallPromptModel;
use App\Service\Curation\Recommendation\Llm\Run\CompletionCallRecorder;
use App\Service\Curation\Recommendation\Llm\Run\RecommendationProviderCall;
use App\Service\Curation\Recommendation\Run\Model\ProviderCallRouteModel;
use App\Service\Curation\StoryAi\Model\StoryAiOutcomeModel;
use App\Service\Curation\StoryAi\Pass\StoryAiPass;

/**
 * One recorded chat call of a story AI task, billed to the pass's run. A rate limit propagates
 * (ProviderRateLimitedException): the caller stops that user rather than spend an attempt on a deferral.
 */
final readonly class StoryAiCalls
{
    public function __construct(
        private RecommendationCompletionRequestFactory $requestFactory,
        private CompletionCallRecorder $callRecorder,
        private RecommendationProviderCall $providerCall,
        private StoryAiRuns $runs,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(string): (T|null) $parse
     *
     * @return StoryAiOutcomeModel<T>
     */
    public function complete(StoryAiPass $pass, CallPromptModel $prompt, \Closure $parse): StoryAiOutcomeModel
    {
        $request = $this->requestFactory->create($pass->connection, $prompt);
        $recordedCall = $this->callRecorder->beginForStoryAiRun($this->runs->openFor($pass), $request);

        try {
            $content = $this->providerCall->complete(
                new ProviderCallRouteModel($pass->connection, RetryPlanModel::deferring()),
                $request,
                $recordedCall,
            );
        } catch (
            ProviderUnreachableException | ProviderRejectedRequestException | CredentialsRejectedException
            | RetryableProviderException | AiKeyUnreadableException $exception
        ) {
            return StoryAiOutcomeModel::failed($exception);
        }

        $parsed = $parse($content);
        if ($parsed === null) {
            $recordedCall->finishUnusable($content);

            return StoryAiOutcomeModel::unusable();
        }

        $recordedCall->finishUsable($content);

        return StoryAiOutcomeModel::usable($parsed);
    }
}
