<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Llm\Run\Support;

use App\Service\Curation\Recommendation\Llm\Completion\Model\CompletionRequestModel;
use App\Service\Curation\Recommendation\Support\PrettyJson;

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
