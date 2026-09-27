<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRunLog;
use App\Service\Recommendation\Prompt\RecommendationCompletionRequestFactory;
use App\Service\Recommendation\Prompt\RecommendationHistoryLoader;
use App\Service\Recommendation\Prompt\RecommendationProfileParser;
use App\Service\Recommendation\Prompt\RecommendationPromptBuilder;
use App\Service\Recommendation\Prompt\RecommendationPromptText;
use App\Service\Recommendation\Prompt\RecommendationResponseSchema;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;

/**
 * The distillation phase's one provider call (#493): the reader's full history in, a short profile out, cached on
 * the settings row so a later run can skip it. It never touches the run's progress.
 */
final readonly class RecommendationProfileDistiller
{
    public function __construct(
        private RecommendationHistoryLoader $historyLoader,
        private RecommendationPromptBuilder $promptBuilder,
        private RecommendationCallRecorder $callRecorder,
        private RecommendationCompletionRequestFactory $requestFactory,
        private RecommendationProviderCall $providerCall,
        private RecommendationProfileParser $profileParser,
        private RecommendationTickCheckpoint $checkpoint,
        private RecommendationSettingsWriter $settingsWriter,
    ) {
    }

    public function distill(TickContext $tick): ProfileDistillationOutcome
    {
        $run = $tick->run;
        $history = $this->historyLoader->load($tick->userId(), $tick->settings);
        $messages = $this->promptBuilder->messagesWithCorrectiveTail(
            $this->promptBuilder->distillMessages($history, $tick->settings),
            $run->getLastInvalidReply(),
            RecommendationPromptText::DISTILL_CORRECTIVE,
        );

        $recordedCall = $this->callRecorder->begin(
            $run,
            RecommendationRunLog::PHASE_DISTILL,
            null,
            $messages,
            $tick->model(),
        );

        $content = $this->providerCall->complete(
            $tick,
            $this->requestFactory->create(
                $tick->connection,
                $messages,
                1,
                RecommendationResponseSchema::Distillation,
            ),
            $recordedCall,
        );

        $result = $this->profileParser->parse($content);
        if (!$result->usable) {
            $recordedCall->finishUnusable($content);
            $this->checkpoint->guard($run);

            return ProfileDistillationOutcome::unusable($content);
        }

        $recordedCall->finishUsable($content);
        $this->checkpoint->guard($run);
        $profile = $result->profile
            ?? throw new \LogicException('A usable profile parse result has no profile text.');
        $this->settingsWriter->storeProfile($run->getUser(), $profile);

        return ProfileDistillationOutcome::usable($profile);
    }
}
