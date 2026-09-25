<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem;

use App\Http\Problem\RateLimitProblems;
use App\Service\RateLimit\Exception\RateLimitedException;
use PHPUnit\Framework\TestCase;

final class RateLimitProblemsTest extends TestCase
{
    public function testRetryAfterHeaderIsAStringOfExactlyTheSeconds(): void
    {
        $resolved = (new RateLimitProblems())->resolve(new RateLimitedException(120));

        self::assertSame(['Retry-After' => '120'], $resolved?->headers);
    }

    public function testAnUnrelatedExceptionResolvesToNull(): void
    {
        self::assertNull((new RateLimitProblems())->resolve(new \RuntimeException()));
    }
}
