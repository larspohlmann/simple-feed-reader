<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Llm\Run;

use App\Entity\InterestTermRun;
use App\Entity\ProfileRun;
use App\Entity\RecommendationRun;
use App\Entity\StoryAiRun;
use App\Service\Curation\Recommendation\Llm\Completion\Model\CompletionRequestModel;
use App\Service\Curation\Recommendation\Llm\Run\Support\RenderedCompletionRequest;
use App\Service\Curation\Recommendation\Run\Model\CallSlotModel;
use App\Service\Curation\Recommendation\Run\Pass\RecordedCall;
use App\Service\Curation\Recommendation\Run\RecommendationCallRecorder;

final readonly class CompletionCallRecorder
{
    public function __construct(private RecommendationCallRecorder $callRecorder)
    {
    }

    public function begin(RecommendationRun $run, CallSlotModel $slot, CompletionRequestModel $request): RecordedCall
    {
        return $this->callRecorder->begin($run, $slot, RenderedCompletionRequest::fromRequest($request));
    }

    public function beginForProfileRun(ProfileRun $profileRun, CompletionRequestModel $request): RecordedCall
    {
        return $this->callRecorder->beginForProfileRun($profileRun, RenderedCompletionRequest::fromRequest($request));
    }

    public function beginForInterestTermRun(InterestTermRun $run, CompletionRequestModel $request): RecordedCall
    {
        return $this->callRecorder->beginForInterestTermRun($run, RenderedCompletionRequest::fromRequest($request));
    }

    public function beginForStoryAiRun(StoryAiRun $storyAiRun, CompletionRequestModel $request): RecordedCall
    {
        return $this->callRecorder->beginForStoryAiRun($storyAiRun, RenderedCompletionRequest::fromRequest($request));
    }
}
