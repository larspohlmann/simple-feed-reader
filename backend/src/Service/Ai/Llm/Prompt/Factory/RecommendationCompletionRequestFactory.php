<?php

declare(strict_types=1);

namespace App\Service\Ai\Llm\Prompt\Factory;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Llm\Completion\Model\CompletionRequestModel;
use App\Service\Ai\Llm\Completion\Model\Reasoning;
use App\Service\Ai\Llm\Prompt\Model\CallPromptModel;
use App\Service\Ai\Llm\Prompt\RecommendationAnswerBudget;

/** Builds every phase's request, so a prompt and its output bound are always derived together. */
final readonly class RecommendationCompletionRequestFactory
{
    public function __construct(private RecommendationAnswerBudget $answerBudget)
    {
    }

    public function create(AiProviderSettings $connection, CallPromptModel $prompt): CompletionRequestModel
    {
        $reasoning = Reasoning::preferredBy($connection);

        return new CompletionRequestModel(
            $connection->getModel() ?? '',
            $prompt->messages,
            $this->answerBudget->outputBoundTokens($prompt->replyItemCount, $prompt->schema, $reasoning),
            $prompt->schema->toJsonSchema(),
            $reasoning,
        );
    }
}
