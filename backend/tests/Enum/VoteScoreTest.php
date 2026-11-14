<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\VoteScore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VoteScoreTest extends TestCase
{
    /** @return iterable<string, array{?int, VoteScore}> */
    public static function networkValues(): iterable
    {
        yield 'absent' => [null, VoteScore::None];
        yield 'unknown' => [5, VoteScore::None];
        yield 'zero' => [0, VoteScore::None];
        yield 'up' => [1, VoteScore::Up];
        yield 'down' => [-1, VoteScore::Down];
    }

    #[DataProvider('networkValues')]
    public function testANetworkValueBecomesAScore(?int $value, VoteScore $expected): void
    {
        self::assertSame($expected, VoteScore::ofNetworkValue($value));
    }
}
