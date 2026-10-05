<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Support;

use App\Service\Ai\Support\RejectingStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RejectingStatusTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function rejectingStatuses(): iterable
    {
        yield 'bad request' => [400];
        yield 'payment required' => [402];
        yield 'not found' => [404];
        yield 'unprocessable' => [422];
        yield 'the last 4xx' => [499];
    }

    /** @return iterable<string, array{int}> */
    public static function otherStatuses(): iterable
    {
        yield 'ok' => [200];
        yield 'a redirect' => [399];
        yield 'unauthorized, a credentials failure' => [401];
        yield 'forbidden, a credentials failure' => [403];
        yield 'request timeout' => [408];
        yield 'too early' => [425];
        yield 'rate limited' => [429];
        yield 'the first 5xx' => [500];
    }

    #[DataProvider('rejectingStatuses')]
    public function testItRejectsTheRequest(int $status): void
    {
        self::assertTrue(RejectingStatus::matches($status));
    }

    #[DataProvider('otherStatuses')]
    public function testItIsNotARejection(int $status): void
    {
        self::assertFalse(RejectingStatus::matches($status));
    }
}
