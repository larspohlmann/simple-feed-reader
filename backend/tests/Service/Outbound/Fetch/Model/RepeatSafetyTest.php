<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Fetch\Model;

use App\Service\Outbound\Fetch\Model\RepeatSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepeatSafetyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, RepeatSafety}>
     */
    public static function methods(): iterable
    {
        yield 'GET' => ['GET', RepeatSafety::Repeatable];
        yield 'lowercase get' => ['get', RepeatSafety::Repeatable];
        yield 'lowercase head' => ['head', RepeatSafety::Repeatable];
        yield 'OPTIONS' => ['OPTIONS', RepeatSafety::Repeatable];
        yield 'POST' => ['POST', RepeatSafety::Write];
        yield 'lowercase post' => ['post', RepeatSafety::Write];
        yield 'lowercase put' => ['put', RepeatSafety::Write];
        yield 'DELETE' => ['DELETE', RepeatSafety::Write];
        yield 'PATCH' => ['PATCH', RepeatSafety::Write];
    }

    #[DataProvider('methods')]
    public function testAMethodIsRepeatableOnlyWhenItChangesNothing(string $method, RepeatSafety $expected): void
    {
        self::assertSame($expected, RepeatSafety::fromMethod($method));
    }
}
