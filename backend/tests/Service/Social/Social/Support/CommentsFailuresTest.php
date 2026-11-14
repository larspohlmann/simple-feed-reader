<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Support\CommentsFailures;
use PHPUnit\Framework\TestCase;

final class CommentsFailuresTest extends TestCase
{
    public function testThrottledKeepsTheNamedWait(): void
    {
        $cause = new SocialNetworkThrottledException('slow down', 12);

        $failure = CommentsFailures::throttled($cause);

        self::assertSame('slow down', $failure->getMessage());
        self::assertSame(12, $failure->retryAfterSeconds);
        self::assertSame($cause, $failure->getPrevious());
    }

    public function testThrottledWithoutANamedWaitWaitsAMinute(): void
    {
        $failure = CommentsFailures::throttled(new SocialNetworkThrottledException('slow down'));

        self::assertSame(60, $failure->retryAfterSeconds);
    }

    public function testUnavailableCarriesTheCause(): void
    {
        $cause = new SocialNetworkUnreachableException('down');

        $failure = CommentsFailures::unavailable($cause);

        self::assertSame('down', $failure->getMessage());
        self::assertSame($cause, $failure->getPrevious());
    }
}
