<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Completion\CompletionRequest;
use App\Service\Ai\Completion\Reasoning;

/** Builds every phase's request, so a prompt and its output bound are always derived together. */
final readonly class RecommendationCompletionRequestFactory
{
    public function create(AiProviderSettings $connection, CallPrompt $prompt): CompletionRequest
    {
        $reasoning = Reasoning::preferredBy($connection);

        return new CompletionRequest(
            $connection->getModel() ?? '',
            $prompt->messages,
            RecommendationAnswerBudget::outputBoundTokens($prompt->replyItemCount, $prompt->schema, $reasoning),
            $prompt->schema->toJsonSchema(),
            $reasoning,
        );
    }
}
