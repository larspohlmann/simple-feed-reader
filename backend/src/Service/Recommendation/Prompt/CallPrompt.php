<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt;

final readonly class CallPrompt
{
    /** @param list<array{role: string, content: string}> $messages */
    public function __construct(
        public array $messages,
        public int $replyItemCount,
        public RecommendationResponseSchema $schema,
    ) {
    }
}
