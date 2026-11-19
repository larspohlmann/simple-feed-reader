<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\ImportRule;

use App\Service\Social\Social\ImportRule\MastodonImportRule;
use App\Service\Social\Social\Model\SocialImportFeedModel;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class MastodonImportRuleTest extends TestCase
{
    public function testNoFollowIsAccountOnly(): void
    {
        self::assertSame([], new MastodonImportRule()->findAccountOnlyFeeds(SocialAccounts::mastodon(), [
            new SocialImportFeedModel('did:plc:rusty', null),
        ]));
    }

    public function testAProfileFeedFollowsItsAccountAndAnyOtherFeedNobody(): void
    {
        $rule = new MastodonImportRule();

        self::assertSame('bob@mastodon.example', $rule->parseFollowedIdentity('https://mastodon.example/@bob.rss'));
        self::assertNull($rule->parseFollowedIdentity('https://bsky.app/profile/did:plc:bob/rss'));
    }

    public function testEveryIdentityIsResolvedAsItIs(): void
    {
        $rule = new MastodonImportRule();

        self::assertTrue($rule->isResolvedIdentity('bob@mastodon.example'));
        self::assertSame('bob@mastodon.example', $rule->fetchResolvedIdentity('bob@mastodon.example'));
    }

    public function testTheFollowsAvatarsAreNotCached(): void
    {
        self::assertFalse(new MastodonImportRule()->allowsFollowAvatarCache());
    }
}
