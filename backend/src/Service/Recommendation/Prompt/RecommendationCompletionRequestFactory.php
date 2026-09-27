<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Completion\CompletionRequest;
use App\Service\Ai\Completion\Reasoning;

/** Builds every phase's request, so a prompt and its output bound are always derived together. */
final readonly class RecommendationCompletionRequestFactory
{
    /** @param list<array{role: string, content: string}> $messages */
    public function create(
        AiProviderSettings $settings,
        array $messages,
        int $replyItemCount,
        RecommendationResponseSchema $responseSchema,
    ): CompletionRequest {
        $reasoning = Reasoning::preferredBy($settings);

        return new CompletionRequest(
            $settings->getModel() ?? '',
            $messages,
            RecommendationAnswerBudget::outputBoundTokens($replyItemCount, $responseSchema, $reasoning),
            $responseSchema->toJsonSchema(),
            $reasoning,
        );
    }
}
