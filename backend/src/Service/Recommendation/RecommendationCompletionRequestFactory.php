<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;

/**
 * Builds the CompletionRequest both provider phases send. Both ask the same
 * question -- what to send, and how much the model may spend on the whole
 * output -- so the output bound is derived in one place. A phase that built
 * its own request could pair a batch prompt with someone else's token ceiling,
 * and the symptom would be a truncated reply rather than an obvious error.
 */
final readonly class RecommendationCompletionRequestFactory
{
    /**
     * @param list<array{role: string, content: string}> $messages
     * @param int                                        $replyItemCount items the reply must cover
     */
    public function create(
        AiProviderSettings $settings,
        array $messages,
        int $replyItemCount,
        RecommendationResponseSchema $responseSchema,
    ): CompletionRequest {
        return new CompletionRequest(
            $settings->getModel() ?? '',
            $messages,
            RecommendationAnswerBudget::outputBoundTokens(
                $replyItemCount,
                $responseSchema,
                $settings->suppressesReasoning(),
            ),
            $responseSchema->toJsonSchema(),
            $settings->suppressesReasoning(),
        );
    }
}
