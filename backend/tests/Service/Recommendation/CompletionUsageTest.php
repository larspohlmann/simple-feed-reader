<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\CompletionUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionUsage::class)]
final class CompletionUsageTest extends TestCase
{
    public function testCarriesEveryFieldTheProviderReported(): void
    {
        $usage = new CompletionUsage(1200, 340, 280, 900, 41_230_000);

        self::assertSame(1200, $usage->promptTokens);
        self::assertSame(340, $usage->completionTokens);
        self::assertSame(280, $usage->reasoningTokens);
        self::assertSame(900, $usage->cachedTokens);
        self::assertSame(41_230_000, $usage->costNanoCredits);
    }

    public function testAnUnpricedCallCarriesTokensWithNoCost(): void
    {
        $usage = new CompletionUsage(10, 5, 0, 0, null);

        self::assertSame(10, $usage->promptTokens);
        self::assertNull($usage->costNanoCredits);
    }
}
