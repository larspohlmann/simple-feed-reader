<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Recommendation\CompletionStreamHeartbeat;

/**
 * For a test that drives the transport without a worker around it. The one
 * production implementation lives in Service/Worker and answers only while a
 * sweep is running, so this is the same nothing it does in a web request —
 * spelled out, rather than left to a mock that would assert the transport's
 * ping count in tests that are not about it.
 */
final readonly class NullCompletionStreamHeartbeat implements CompletionStreamHeartbeat
{
    public function beat(): void
    {
    }
}
