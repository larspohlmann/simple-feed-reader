<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Llm\Run\Pass;

use App\Service\Curation\Recommendation\Llm\Completion\CompletionStreamObserver\CompletionStreamObserverInterface;
use App\Service\Curation\Recommendation\Run\Model\CallProgressModel;
use App\Service\Curation\Recommendation\Run\Pass\RecordedCall;

/** Hands a streamed completion's progress to the call's run-log recording. */
final readonly class RecordedCallObserver implements CompletionStreamObserverInterface
{
    public function __construct(private RecordedCall $recordedCall)
    {
    }

    public function streamProgressed(CallProgressModel $progress): void
    {
        $this->recordedCall->progressed($progress);
    }
}
