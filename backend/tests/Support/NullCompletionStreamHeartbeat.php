<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface;

/**
 * A heartbeat that does nothing, for tests that drive the transport but are not about its pings; a mock would assert
 * a ping count they do not care about.
 */
final readonly class NullCompletionStreamHeartbeat implements CompletionStreamHeartbeatInterface
{
    public function beat(): void
    {
    }
}
