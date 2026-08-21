<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\ProfileDistillationOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProfileDistillationOutcome::class)]
final class ProfileDistillationOutcomeTest extends TestCase
{
    public function testUnusableOutcomeReturnsTheOffendingReply(): void
    {
        $outcome = ProfileDistillationOutcome::unusable('garbage');

        self::assertFalse($outcome->usable);
        self::assertSame('garbage', $outcome->requireUnusableReply());
    }

    public function testAUsableOutcomeHasNoInvalidReplyToRetry(): void
    {
        $outcome = ProfileDistillationOutcome::usable('Likes Rust.');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A usable profile distillation outcome has no invalid reply to retry.');
        $outcome->requireUnusableReply();
    }
}
