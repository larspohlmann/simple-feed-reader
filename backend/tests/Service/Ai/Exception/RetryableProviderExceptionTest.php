<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Exception;

use App\Service\Ai\Exception\RetryableProviderException;
use PHPUnit\Framework\TestCase;

final class RetryableProviderExceptionTest extends TestCase
{
    public function testCarriesStatusAndRetryAfter(): void
    {
        $exception = new RetryableProviderException(429, 12);

        self::assertSame(429, $exception->status());
        self::assertSame(12, $exception->retryAfterSeconds());
        self::assertStringContainsString('429', $exception->getMessage());
    }

    public function testRetryAfterIsOptional(): void
    {
        self::assertNull((new RetryableProviderException(503))->retryAfterSeconds());
    }
}
