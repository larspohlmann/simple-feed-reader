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

    public function testAnAuthorFeedOfADidExportsAsItsProfilesRss(): void
    {
        self::assertSame(
            'https://bsky.app/profile/did:plc:bob/rss',
            new BlueskyProfileFeedRule()->buildExportUrl('social:bluesky:4:author:did:plc:bob'),
        );
    }

    public function testAnRssFeedAndAnAuthorFeedOfAHandleExportAsTheyAre(): void
    {
        $rule = new BlueskyProfileFeedRule();

        self::assertNull($rule->buildExportUrl('https://bsky.app/profile/did:plc:bob/rss'));
        self::assertNull($rule->buildExportUrl('social:bluesky:4:author:carol.bsky.social'));
        self::assertNull($rule->buildExportUrl('social:mastodon:4:author:did:plc:bob'));
    }
}
