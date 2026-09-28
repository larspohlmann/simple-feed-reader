<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion\CompletionStreamObserver;

use App\Service\Ai\Completion\Model\CompletionStreamProgressModel;

/**
 * The observer for callers with nothing to observe — an explicit argument
 * instead of a nullable parameter, so every call site states its intent.
 */
final readonly class NullCompletionStreamObserver implements CompletionStreamObserverInterface
{
    public function streamProgressed(CompletionStreamProgressModel $progress): void
    {
    }
}
