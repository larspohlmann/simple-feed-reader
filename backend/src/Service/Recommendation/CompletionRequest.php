<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What to ask a provider for, as one value.
 *
 * The three fields travel together and are decided together: the messages
 * imply how many items the reply covers, and that count is what sizes
 * `maxAnswerTokens`. Passing them separately let the answer bound drift from
 * the prompt it belongs to, which is the whole failure this type exists to
 * prevent.
 */
final readonly class CompletionRequest
{
    /**
     * @param list<array{role: string, content: string}> $messages
     * @param int                                        $maxAnswerTokens what the provider may spend answering, from
     *                                                                    RecommendationPromptBuilder::answerTokenReserve()
     */
    public function __construct(
        public string $model,
        public array $messages,
        public int $maxAnswerTokens,
    ) {
    }
}
