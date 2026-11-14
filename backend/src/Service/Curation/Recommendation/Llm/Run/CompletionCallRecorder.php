<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run;

use App\Entity\ProfileRun;
use App\Entity\RecommendationRun;
use App\Service\Recommendation\Llm\Completion\Model\CompletionRequestModel;
use App\Service\Recommendation\Llm\Run\Support\RenderedCompletionRequest;
use App\Service\Recommendation\Run\Model\CallSlotModel;
use App\Service\Recommendation\Run\Pass\RecordedCall;
use App\Service\Recommendation\Run\RecommendationCallRecorder;

final readonly class CompletionCallRecorder
{
    public function __construct(private RecommendationCallRecorder $callRecorder)
    {
    }

    public function begin(RecommendationRun $run, CallSlotModel $slot, CompletionRequestModel $request): RecordedCall
    {
        return $this->callRecorder->begin($run, $slot, RenderedCompletionRequest::of($request));
    }

    public function beginForProfileRun(ProfileRun $profileRun, CompletionRequestModel $request): RecordedCall
    {
        return $this->callRecorder->beginForProfileRun($profileRun, RenderedCompletionRequest::of($request));
    }
}
