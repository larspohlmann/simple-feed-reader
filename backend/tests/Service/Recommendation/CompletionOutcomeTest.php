<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Recommendation\CompletionOutcome;
use PHPUnit\Framework\TestCase;

final class CompletionOutcomeTest extends TestCase
{
    public function testARetryableFailureIsFlaggedAndCarriesItsRetryAfter(): void
    {
        $outcome = CompletionOutcome::failure(new RetryableProviderException(429, 7));

        self::assertTrue($outcome->isFailure());
        self::assertTrue($outcome->isRetryable());
        self::assertSame(7, $outcome->retryAfterSeconds());
    }

    public function testAPlainTransportFailureIsNotRetryable(): void
    {
        $outcome = CompletionOutcome::failure(new ProviderUnreachableException('gone'));

        self::assertFalse($outcome->isRetryable());
        self::assertNull($outcome->retryAfterSeconds());
    }

    public function testAnAnswerIsNotRetryable(): void
    {
        $outcome = CompletionOutcome::answer('{}');

        self::assertFalse($outcome->isRetryable());
        self::assertNull($outcome->retryAfterSeconds());
    }
}
