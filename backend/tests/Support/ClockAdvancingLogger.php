<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Clock\MockClock;

/** Advances the clock when one message is logged, so the step that logs it takes time a test can see afterwards. */
final class ClockAdvancingLogger extends AbstractLogger
{
    public function __construct(
        private readonly MockClock $clock,
        private readonly string $message,
        private readonly int $seconds,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if ($this->message === (string) $message) {
            $this->clock->sleep($this->seconds);
        }
    }
}
