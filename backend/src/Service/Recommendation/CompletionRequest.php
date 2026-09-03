<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What to ask a provider for, as one value.
 *
 * The fields are decided together: the messages imply how many items the reply
 * covers, which sizes `maxAnswerTokens`, and the phase that builds them fixes
 * the answer's schema. Passing them separately let the answer bound drift from
 * its prompt — the failure this type exists to prevent.
 */
final readonly class CompletionRequest
{
    /**
     * @param list<array{role: string, content: string}> $messages
     * @param int                                        $maxAnswerTokens what the provider may spend answering, from
     *                                                                    RecommendationAnswerBudget::outputBoundTokens()
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
