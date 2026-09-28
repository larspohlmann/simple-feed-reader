<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion\Model;

/**
 * What to ask a provider for, as one value: the messages imply how many items the reply covers, which sizes
 * `maxAnswerTokens`, so the bound cannot drift from the prompt it belongs to.
 */
final readonly class CompletionRequestModel
{
    /** @param list<array{role: string, content: string}> $messages */
    public function __construct(
        public string $model,
        public array $messages,
        public int $maxAnswerTokens,
        public JsonSchemaModel $responseSchema,
        public Reasoning $reasoning,
    ) {
    }
}
