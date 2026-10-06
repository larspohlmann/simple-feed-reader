<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run\Support;

use App\Service\Recommendation\Llm\Completion\Model\CompletionRequestModel;
use App\Service\Recommendation\Support\PrettyJson;

final class RenderedCompletionRequest
{
    public static function of(CompletionRequestModel $request): string
    {
        return PrettyJson::of(['model' => $request->model, 'messages' => $request->messages]);
    }

    private function __construct()
    {
    }
}
