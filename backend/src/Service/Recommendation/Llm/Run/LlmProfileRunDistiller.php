<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run;

use App\Service\Recommendation\Llm\Prompt\Factory\RecommendationCompletionRequestFactory;
use App\Service\Recommendation\Llm\Prompt\Model\CallPromptModel;
use App\Service\Recommendation\Llm\Prompt\Model\RecommendationResponseSchema;
use App\Service\Recommendation\Llm\Prompt\RecommendationProfileParser;
use App\Service\Recommendation\Llm\Prompt\RecommendationPromptBuilder;
use App\Service\Recommendation\Llm\Prompt\Support\RecommendationPromptText;
use App\Service\Recommendation\Profile\Model\ProfileDistillationOutcomeModel;
use App\Service\Recommendation\Profile\Pass\ProfileTick;
use App\Service\Recommendation\Profile\ProfileRunDistiller\ProfileRunDistillerInterface;

final readonly class LlmProfileRunDistiller implements ProfileRunDistillerInterface
{
    public function __construct(
        private RecommendationPromptBuilder $promptBuilder,
        private RecommendationCompletionRequestFactory $requestFactory,
        private CompletionCallRecorder $callRecorder,
        private RecommendationProviderCall $providerCall,
        private RecommendationProfileParser $profileParser,
    ) {
    }

    public function distill(ProfileTick $tick): ProfileDistillationOutcomeModel
    {
        $request = $this->requestFactory->create($tick->connection, new CallPromptModel(
            $this->promptBuilder->messagesWithCorrectiveTail(
                $this->promptBuilder->distillMessages($tick->history, $tick->settings),
                $tick->profileRun->getLastInvalidReply(),
                RecommendationPromptText::DISTILL_CORRECTIVE,
            ),
            1,
            RecommendationResponseSchema::Distillation,
        ));
        $recordedCall = $this->callRecorder->beginForProfileRun($tick->profileRun, $request);
        $content = $this->providerCall->complete($tick->callRoute(), $request, $recordedCall);
        $result = $this->profileParser->parse($content);

        if (!$result->usable) {
            $recordedCall->finishUnusable($content);

            return ProfileDistillationOutcomeModel::unusable($content);
        }

        $recordedCall->finishUsable($content);

        return ProfileDistillationOutcomeModel::usable(
            $result->profile ?? throw new \LogicException('A usable profile parse result has no profile text.'),
        );
    }
}
