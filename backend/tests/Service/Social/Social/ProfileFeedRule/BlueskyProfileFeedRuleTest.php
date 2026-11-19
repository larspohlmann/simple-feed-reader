<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\ProfileFeedRule;

use App\Service\Social\Social\ProfileFeedRule\BlueskyProfileFeedRule;
use PHPUnit\Framework\TestCase;

final class BlueskyProfileFeedRuleTest extends TestCase
{
    public function testADidIsItsOwnHandle(): void
    {
        self::assertSame('did:plc:bob', new BlueskyProfileFeedRule()->formatHandle('did:plc:bob'));
    }

    public function testAHandleGetsALeadingAt(): void
    {
        self::assertSame('@carol.bsky.social', new BlueskyProfileFeedRule()->formatHandle('carol.bsky.social'));
    }
}
