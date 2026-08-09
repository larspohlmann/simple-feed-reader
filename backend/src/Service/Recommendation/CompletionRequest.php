<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What to ask a provider for, as one value.
 *
 * The fields travel together and are decided together: the messages imply how
 * many items the reply covers, and that count is what sizes `maxAnswerTokens`;
 * the same phase that builds the messages also fixes the answer's schema.
 * Passing them separately let the answer bound drift from the prompt it belongs
 * to, which is the whole failure this type exists to prevent.
 */
final readonly class CompletionRequest
{
    /**
     * @param list<array{role: string, content: string}> $messages
     * @param int                                        $maxAnswerTokens what the provider may spend answering, from
     *                                                                    RecommendationPromptBuilder::answerTokenReserve()
     * @param JsonSchema                                 $responseSchema  the structured-output shape the answer must
     *                                                                    take, sent as a json_schema response format
     */
    public function __construct(
        public string $model,
        public array $messages,
        public int $maxAnswerTokens,
        public JsonSchema $responseSchema,
        // When true, the request asks the provider not to reason (#323). Sent as
        // the OpenRouter `reasoning: {"effort": "none"}` extension; an endpoint
        // that does not know the field ignores it.
        public bool $suppressReasoning,
    ) {
    }
}
