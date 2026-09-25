<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Runs an attempt expected to fail and asserts it threw the given exception.
 * Lifted out of near-identical private `assertRefused()` methods in
 * ActionTokenServiceTest, LoginCodeStoreTest and OAuthStateStoreTest — each
 * store's `consume()` throws its own exception type, so this takes it as an
 * argument rather than fixing one in a `catch` clause.
 */
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
