<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt\Model;

final readonly class CallPromptModel
{
    /** @param list<array{role: string, content: string}> $messages */
    public function __construct(
        public array $messages,
        public int $replyItemCount,
        public RecommendationResponseSchema $schema,
    ) {
    }
}
