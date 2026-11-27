<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Llm\Run;

use App\Service\Curation\Recommendation\Llm\Prompt\Factory\RecommendationCompletionRequestFactory;
use App\Service\Curation\Recommendation\Llm\Prompt\InterestTermParser;
use App\Service\Curation\Recommendation\Llm\Prompt\Model\CallPromptModel;
use App\Service\Curation\Recommendation\Llm\Prompt\RecommendationPromptBuilder;
use App\Service\Curation\Recommendation\Llm\Prompt\Support\RecommendationPromptText;
use App\Service\Curation\Recommendation\Stream\InterestTermExtractor\InterestTermExtractorInterface;
use App\Service\Curation\Recommendation\Stream\InterestTermStems;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermOutcomeModel;
use App\Service\Curation\Recommendation\Stream\Pass\InterestTermPass;

final readonly class LlmInterestTermExtractor implements InterestTermExtractorInterface
{
    public function __construct(
        private RecommendationPromptBuilder $promptBuilder,
        private RecommendationCompletionRequestFactory $requestFactory,
        private CompletionCallRecorder $callRecorder,
        private RecommendationProviderCall $providerCall,
        private InterestTermParser $termParser,
        private InterestTermStems $stems,
    ) {
    }

    public function extractTerms(InterestTermPass $pass): InterestTermOutcomeModel
    {
        $request = $this->requestFactory->create($pass->connection, CallPromptModel::interestTerms(
            $this->promptBuilder->messagesWithCorrectiveTail(
                $this->promptBuilder->interestTermMessages($pass->profileText, $pass->languages),
                $pass->run->getLastInvalidReply(),
                RecommendationPromptText::INTEREST_TERMS_CORRECTIVE,
            ),
        ));
        $recordedCall = $this->callRecorder->beginForInterestTermRun($pass->run, $request);
        $content = $this->providerCall->complete($pass->callRoute(), $request, $recordedCall);
        $stems = $this->stems->buildTermStems($this->termParser->parse($content, $pass->languages));
        if ([] === $stems) {
            $recordedCall->finishUnusable($content);

            return InterestTermOutcomeModel::unusable($content);
        }

        $recordedCall->finishUsable($content);

        return InterestTermOutcomeModel::usable($stems);
    }
}
