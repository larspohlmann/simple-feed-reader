<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\PostAddressRule;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use App\Service\Social\Social\PostAddressRule\BlueskyPostAddressRule;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyPostAddressRuleTest extends TestCase
{
    public function testABlueskyPostIsDeletedByItsAtUri(): void
    {
        $post = new SocialPostAddressModel(SocialNetwork::Bluesky, 'at://did:plc:alice/app.bsky.feed.post/3own');

        self::assertSame(
            'at://did:plc:alice/app.bsky.feed.post/3own',
            new BlueskyPostAddressRule()->requireOwnPostId($post, SocialAccounts::bluesky()),
        );
    }

    #[DataProvider('blueskyReplies')]
    public function testABlueskyReplyIsTheAccountsRecordUnderTheRecordKeyOfItsUrl(string $url): void
    {
        self::assertSame(
            'at://did:plc:alice/app.bsky.feed.post/3rep',
            new BlueskyPostAddressRule()->requireOwnReplyId($url, SocialAccounts::bluesky()),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function blueskyReplies(): iterable
    {
        yield 'by DID' => ['https://bsky.app/profile/did:plc:alice/post/3rep'];
        yield 'by handle' => ['https://bsky.app/profile/alice.bsky.social/post/3rep'];
    }

    public function testAReplyUrlThatNamesNoBlueskyPostIsRefused(): void
    {
        $this->expectException(SocialPostRefusedException::class);

        new BlueskyPostAddressRule()->requireOwnReplyId(
            'https://bsky.app/profile/did:plc:alice',
            SocialAccounts::bluesky(),
        );
    }

    public function testABlueskyReplyComesBackUnderTheAccountsAtUri(): void
    {
        self::assertSame(
            ['at://did:plc:alice/app.bsky.feed.post/3rep'],
            new BlueskyPostAddressRule()->requireOwnReplyGuids(
                'https://bsky.app/profile/alice.bsky.social/post/3rep',
                SocialAccounts::bluesky(),
            ),
        );
    }
}
