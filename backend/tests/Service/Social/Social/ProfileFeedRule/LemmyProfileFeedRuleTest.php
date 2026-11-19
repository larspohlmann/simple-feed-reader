<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\ProfileFeedRule;

use App\Service\Social\Social\ProfileFeedRule\LemmyProfileFeedRule;
use PHPUnit\Framework\TestCase;

final class LemmyProfileFeedRuleTest extends TestCase
{
    public function testALemmyUserKeepsItsLeadingAt(): void
    {
        self::assertSame('@ada@feddit.example', new LemmyProfileFeedRule()->formatHandle('@ada@feddit.example'));
    }

    public function testALemmyCommunityGetsAnExclamationMark(): void
    {
        self::assertSame('!rss@feddit.example', new LemmyProfileFeedRule()->formatHandle('rss@feddit.example'));
    }

    public function testAUserIdentityBuildsNoFeed(): void
    {
        self::assertNull(new LemmyProfileFeedRule()->buildFeedUrl('@ada@feddit.example'));
    }

    public function testNoFeedChangesOnExport(): void
    {
        self::assertNull(new LemmyProfileFeedRule()->buildExportUrl('social:lemmy:4:author:123'));
    }
}
