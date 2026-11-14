<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Service\Ai\Model\ProviderCallUsageModel;

/**
 * One progress report of a provider call. `wireBytes` is kept beside `answerSoFar` because a reasoning model sends
 * megabytes while its answer stays empty. `finishReason` (`length`: `max_tokens` cut the answer) and `usage` stay
 * null until the provider sends them.
 */
final readonly class CallProgressModel
{
    public function __construct(
        public string $answerSoFar,
        public int $wireBytes,
        public ?string $finishReason = null,
        public ?ProviderCallUsageModel $usage = null,
    ) {
    }
}
