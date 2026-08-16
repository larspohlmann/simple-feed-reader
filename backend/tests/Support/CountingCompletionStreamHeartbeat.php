<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Recommendation\CompletionStreamHeartbeat;

/**
 * Counts the transport's pings. The production implementation decides for
 * itself whether a ping costs a write, so what the transport owes is the ping
 * — and that is what this observes.
 */
final class CountingCompletionStreamHeartbeat implements CompletionStreamHeartbeat
{
    private int $beats = 0;

    public function beat(): void
    {
        ++$this->beats;
    }

    public function beats(): int
    {
        return $this->beats;
    }
}
