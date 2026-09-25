<?php

declare(strict_types=1);

namespace App\Tests\Service\RateLimit\Exception;

use App\Service\RateLimit\Exception\RateLimitedException;
use PHPUnit\Framework\TestCase;

final class RateLimitedExceptionTest extends TestCase
{
    public function testItCarriesTheRetryDelayAndAReadableMessage(): void
    {
        $exception = new RateLimitedException(120);

        self::assertSame(120, $exception->retryAfterSeconds);
        self::assertSame('Too many attempts. Try again later.', $exception->getMessage());
    }
}
