<?php

declare(strict_types=1);

namespace App\Enum;

/** The request field that bounds a completion's output; OpenAI's reasoning models refuse the older `max_tokens`. */
enum TokenLimitParameter: string
{
    case MaxTokens = 'max_tokens';
    case MaxCompletionTokens = 'max_completion_tokens';
}
