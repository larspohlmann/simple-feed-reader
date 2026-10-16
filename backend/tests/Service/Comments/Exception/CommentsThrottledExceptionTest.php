<?php

declare(strict_types=1);

namespace App\Tests\Service\Comments\Exception;

use App\Service\Comments\Exception\CommentsThrottledException;
use PHPUnit\Framework\TestCase;

final class CommentsThrottledExceptionTest extends TestCase
{
    public function testItKeepsItsMessageWaitAndCause(): void
    {
        $cause = new \RuntimeException('429');

        $exception = new CommentsThrottledException('Fewer requests, please.', 30, $cause);

        self::assertSame('Fewer requests, please.', $exception->getMessage());
        self::assertSame(30, $exception->retryAfterSeconds);
        self::assertSame($cause, $exception->getPrevious());
    }
}
