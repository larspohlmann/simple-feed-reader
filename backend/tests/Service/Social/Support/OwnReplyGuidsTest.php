<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Support\OwnReplyGuids;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class OwnReplyGuidsTest extends TestCase
{
    public function testABlueskyReplyComesBackUnderTheAccountsAtUri(): void
    {
        self::assertSame(
            ['at://did:plc:alice/app.bsky.feed.post/3rep'],
            OwnReplyGuids::ofUrl('https://bsky.app/profile/alice.bsky.social/post/3rep', SocialAccounts::bluesky()),
        );
    }

    public function testAMastodonReplyComesBackUnderItsStatusUriOrItsWebAddress(): void
    {
        self::assertSame(
            ['https://mastodon.example/users/alice/statuses/121', 'https://mastodon.example/@alice/121'],
            OwnReplyGuids::ofUrl('https://mastodon.example/@alice/121', SocialAccounts::mastodon()),
        );
    }

    public function testAnAddressThatNamesNoMastodonStatusIsRefused(): void
    {
        $this->expectException(SocialPostRefusedException::class);

        OwnReplyGuids::ofUrl('https://mastodon.example/@alice', SocialAccounts::mastodon());
    }
}
