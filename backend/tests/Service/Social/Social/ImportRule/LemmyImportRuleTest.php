<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\ImportRule;

use App\Service\Social\Social\ImportRule\LemmyImportRule;
use App\Service\Social\Social\Model\SocialImportFeedModel;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class LemmyImportRuleTest extends TestCase
{
    public function testNoFollowIsAccountOnly(): void
    {
        $account = SocialAccounts::lemmyOf(SocialAccounts::owner());

        self::assertSame([], new LemmyImportRule()->findAccountOnlyFeeds($account, [
            new SocialImportFeedModel('rss@feddit.example', null),
        ]));
    }

    public function testACommunityFeedFollowsItsCommunityAndAnyOtherFeedNobody(): void
    {
        $rule = new LemmyImportRule();

        self::assertSame('rss@feddit.example', $rule->parseFollowedIdentity('https://feddit.example/feeds/c/rss.xml'));
        self::assertNull($rule->parseFollowedIdentity('https://bsky.app/profile/did:plc:bob/rss'));
    }

    public function testEveryIdentityIsResolvedAsItIs(): void
    {
        $rule = new LemmyImportRule();

        self::assertTrue($rule->isResolvedIdentity('rss@feddit.example'));
        self::assertSame('rss@feddit.example', $rule->fetchResolvedIdentity('rss@feddit.example'));
    }

    public function testTheFollowsAvatarsAreNotCached(): void
    {
        self::assertFalse(new LemmyImportRule()->allowsFollowAvatarCache());
    }
}
