<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Support;

use App\Service\Curation\Recommendation\Support\TokenEstimate;
use PHPUnit\Framework\TestCase;

final class TokenEstimateTest extends TestCase
{
    /** 17 bytes, `ä` two of them: four bytes a token, plus one. */
    public function testItCountsTheUtf8BytesFourToAToken(): void
    {
        self::assertSame(5, TokenEstimate::estimateTokens('ä/abcdefghijklmn'));
    }

    public function testALengthIsCountedAlike(): void
    {
        self::assertSame(1, TokenEstimate::estimateTokensForLength(3));
        self::assertSame(2, TokenEstimate::estimateTokensForLength(4));
    }
}
