<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Exception;

use App\Service\Ai\Exception\ProviderRateLimitedException;
use PHPUnit\Framework\TestCase;

final class ProviderRateLimitedExceptionTest extends TestCase
{
    public function testItStatesTheDeferralInItsMessage(): void
    {
        $exception = new ProviderRateLimitedException(30.0);

        self::assertSame('Provider rate limited; deferring for 30 s.', $exception->getMessage());
    }

    public function testItCarriesTheWait(): void
    {
        self::assertSame(30.0, (new ProviderRateLimitedException(30.0))->waitSeconds());
    }
}
