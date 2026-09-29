<?php

declare(strict_types=1);

namespace App\Tests\Support;

trait AssertsRefusal
{
    /**
     * @param class-string<\Throwable> $expectedException
     */
    private function assertRefused(callable $attempt, string $expectedException, string $failureMessage): void
    {
        try {
            $attempt();
            self::fail($failureMessage);
        } catch (\Throwable $exception) {
            if (!$exception instanceof $expectedException) {
                throw $exception;
            }

            $this->addToAssertionCount(1);
        }
    }
}
