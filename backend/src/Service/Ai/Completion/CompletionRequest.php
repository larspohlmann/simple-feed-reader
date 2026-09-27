<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion;

/**
 * What to ask a provider for, as one value: the messages imply how many items the reply covers, which sizes
 * `maxAnswerTokens`, so the bound cannot drift from the prompt it belongs to.
 */
final readonly class CompletionRequest
{
    /** @param list<array{role: string, content: string}> $messages */
    public function __construct(
        public string $model,
        public array $messages,
        public int $maxAnswerTokens,
        public JsonSchema $responseSchema,
        public Reasoning $reasoning,
    ) {
    }
}
