<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Prompt\Factory;

use App\Entity\AiProviderSettings;
use App\Service\Recommendation\Llm\Completion\Model\CompletionRequestModel;
use App\Service\Recommendation\Llm\Prompt\Model\CallPromptModel;
use App\Service\Recommendation\Llm\Prompt\Model\OutputLimitModel;
use App\Service\Recommendation\Llm\Prompt\RecommendationAnswerBudget;

/** Builds every phase's request, so a prompt and its output bound are always derived together. */
final readonly class RecommendationCompletionRequestFactory
{
    public function __construct(private RecommendationAnswerBudget $answerBudget)
    {
    }

    public function create(AiProviderSettings $connection, CallPromptModel $prompt): CompletionRequestModel
    {
        $limit = OutputLimitModel::of($connection);

        return new CompletionRequestModel(
            $connection->getModel() ?? '',
            $prompt->messages,
            $this->answerBudget->outputBoundTokens($prompt->replyItemCount, $prompt->schema, $limit),
            $prompt->schema->toJsonSchema(),
            $limit->reasoning,
            $connection->getTokenLimitParameter(),
        );
    }
}
