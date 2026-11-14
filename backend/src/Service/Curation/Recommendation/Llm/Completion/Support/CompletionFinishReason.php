<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Completion\Support;

/**
 * Who ended an answer. OpenRouter reports an upstream that broke off mid-stream as `error`, OpenAI a filtered answer
 * as `content_filter`; `length` is the caller's own `max_tokens` ceiling, not a cut.
 */
final readonly class CompletionFinishReason
{
    public static function cutByProvider(?string $finishReason): bool
    {
        return 'error' === $finishReason || 'content_filter' === $finishReason;
    }

    private function __construct()
    {
    }
}
