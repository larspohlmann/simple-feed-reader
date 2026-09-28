<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion\CompletionStreamObserver;

use App\Service\Ai\Completion\CompletionStreamProgress;

/**
 * The observer for callers with nothing to observe — an explicit argument
 * instead of a nullable parameter, so every call site states its intent.
 */
final readonly class NullCompletionStreamObserver implements CompletionStreamObserverInterface
{
    public function streamProgressed(CompletionStreamProgress $progress): void
    {
    }
}
