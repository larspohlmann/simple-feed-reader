<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion\Model;

/**
 * One progress report of a streamed call. `wireBytes` is kept beside `answerSoFar` because a reasoning model sends
 * megabytes while its answer stays empty. `finishReason` (`length`: `max_tokens` cut the answer) and `usage` stay
 * null until the provider sends them.
 */
final readonly class CompletionStreamProgressModel
{
    public function __construct(
        public string $answerSoFar,
        public int $wireBytes,
        public ?string $finishReason = null,
        public ?CompletionUsageModel $usage = null,
    ) {
    }
}
