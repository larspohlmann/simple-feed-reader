<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Support;

use App\Service\Recommendation\Support\TokenEstimate;
use PHPUnit\Framework\TestCase;

final class TokenEstimateTest extends TestCase
{
    /** 17 bytes, `ä` two of them: four bytes a token, plus one. */
    public function testItCountsTheUtf8BytesFourToAToken(): void
    {
        self::assertSame(5, TokenEstimate::of('ä/abcdefghijklmn'));
    }

    public function testALengthIsCountedAlike(): void
    {
        self::assertSame(1, TokenEstimate::ofLength(3));
        self::assertSame(2, TokenEstimate::ofLength(4));
    }
}
