<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run\Support;

use App\Service\Recommendation\Llm\Completion\Model\CompletionRequestModel;

final class RenderedCompletionRequest
{
    /** Pretty-printed for the human the debug view exists for: the payload as sent, minus transport framing. */
    public static function of(CompletionRequestModel $request): string
    {
        return json_encode(
            ['model' => $request->model, 'messages' => $request->messages],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }

    private function __construct()
    {
    }
}
