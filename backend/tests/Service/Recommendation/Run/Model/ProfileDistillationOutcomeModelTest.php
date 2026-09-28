<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\Model;

use App\Service\Recommendation\Run\Model\ProfileDistillationOutcomeModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProfileDistillationOutcomeModel::class)]
final class ProfileDistillationOutcomeModelTest extends TestCase
{
    public function testUnusableOutcomeReturnsTheOffendingReply(): void
    {
        $outcome = ProfileDistillationOutcomeModel::unusable('garbage');

        self::assertFalse($outcome->usable);
        self::assertSame('garbage', $outcome->requireUnusableReply());
    }

    public function testAUsableOutcomeHasNoInvalidReplyToRetry(): void
    {
        $outcome = ProfileDistillationOutcomeModel::usable('Likes Rust.');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A usable profile distillation outcome has no invalid reply to retry.');
        $outcome->requireUnusableReply();
    }
}
