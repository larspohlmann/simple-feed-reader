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
    public function __construct(private RecommendationPromptBuilder $promptBuilder)
    {
    }

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
            $this->outputBound($settings, $replyItemCount),
            $responseSchema->toJsonSchema(),
            $settings->suppressesReasoning(),
        );
    }

    /**
     * What the provider may spend on the whole output.
     *
     * The reasoning headroom pays for a thinking phase, and a connection that
     * suppresses reasoning has none. Paying for one anyway is not free: it is
     * the only bound that stops a model which has started to repeat itself, so
     * a 45-item batch that needs 1800 tokens was licensed to emit 33800, and a
     * 4B model looping on invented ids spent an hour reaching that ceiling
     * before the wall clock cut it (#437).
     */
    private function outputBound(AiProviderSettings $settings, int $replyItemCount): int
    {
        if ($settings->suppressesReasoning()) {
            return $this->promptBuilder->answerTokenReserve($replyItemCount);
        }

        return $this->promptBuilder->outputTokenReserve($replyItemCount);
    }
}
