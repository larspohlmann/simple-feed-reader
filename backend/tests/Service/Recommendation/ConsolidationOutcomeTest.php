<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\ConsolidationOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsolidationOutcome::class)]
final class ConsolidationOutcomeTest extends TestCase
{
    public function testUnusableOutcomeReturnsTheOffendingReply(): void
    {
        $outcome = ConsolidationOutcome::unusable('garbage', [['id' => 1, 'score' => 10, 'reason' => 'r']]);

        self::assertSame('garbage', $outcome->requireUnusableReply());
        self::assertSame([['id' => 1, 'score' => 10, 'reason' => 'r']], $outcome->requireFallbackPool());
    }

    public function testAUsableOutcomeHasNoInvalidReplyToRetry(): void
    {
        $outcome = ConsolidationOutcome::finalizeWith([['id' => 1, 'score' => 10, 'reason' => 'r']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A usable consolidation outcome has no invalid reply to retry.');
        $outcome->requireUnusableReply();
    }

    public function testAUsableOutcomeHasNoFallbackPoolToDegradeTo(): void
    {
        $outcome = ConsolidationOutcome::finalizeWith([['id' => 1, 'score' => 10, 'reason' => 'r']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A usable consolidation outcome has no fallback pool to degrade to.');
        $outcome->requireFallbackPool();
    }
}
