<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Jev\Support;

use App\Service\Recommendation\Jev\Support\JevTokenEstimate;
use PHPUnit\Framework\TestCase;

final class JevTokenEstimateTest extends TestCase
{
    /** 19 bytes as sent, `ä` two of them and `/` unescaped: four bytes a token, plus one. */
    public function testItCountsTheUnescapedUtf8BytesFourToAToken(): void
    {
        self::assertSame(5, JevTokenEstimate::ofJson('ä/abcdefghijklmn'));
    }
}
