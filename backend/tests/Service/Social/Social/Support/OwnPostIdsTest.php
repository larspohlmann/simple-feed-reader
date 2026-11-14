<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\Support\OwnPostIds;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OwnPostIdsTest extends TestCase
{
    public function testABlueskyPostIsDeletedByItsAtUri(): void
    {
        $post = new SocialPostAddressModel(SocialNetwork::Bluesky, 'at://did:plc:alice/app.bsky.feed.post/3own');

        self::assertSame(
            'at://did:plc:alice/app.bsky.feed.post/3own',
            OwnPostIds::ofAddress($post, SocialAccounts::bluesky()),
        );
    }

    #[DataProvider('blueskyReplies')]
    public function testABlueskyReplyIsTheAccountsRecordUnderTheRecordKeyOfItsUrl(string $url): void
    {
        self::assertSame(
            'at://did:plc:alice/app.bsky.feed.post/3rep',
            OwnPostIds::ofReplyUrl($url, SocialAccounts::bluesky()),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function blueskyReplies(): iterable
    {
        yield 'by DID' => ['https://bsky.app/profile/did:plc:alice/post/3rep'];
        yield 'by handle' => ['https://bsky.app/profile/alice.bsky.social/post/3rep'];
    }

    #[DataProvider('mastodonStatuses')]
    public function testAMastodonStatusIsDeletedByItsIdOnTheAccountsServer(string $address): void
    {
        $post = new SocialPostAddressModel(SocialNetwork::Mastodon, $address);

        self::assertSame('120', OwnPostIds::ofAddress($post, SocialAccounts::mastodon()));
        self::assertSame('120', OwnPostIds::ofReplyUrl($address, SocialAccounts::mastodon()));
    }

    /** @return iterable<string, array{string}> */
    public static function mastodonStatuses(): iterable
    {
        yield 'web address' => ['https://mastodon.example/@alice/120'];
        yield 'activity address' => ['https://Mastodon.Example/users/alice/statuses/120'];
    }

    #[DataProvider('unaddressable')]
    public function testAPostThatCannotBeNamedOnTheAccountsServerIsRefused(string $url, bool $onBluesky): void
    {
        $this->expectException(SocialPostRefusedException::class);

        OwnPostIds::ofReplyUrl($url, $onBluesky ? SocialAccounts::bluesky() : SocialAccounts::mastodon());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function unaddressable(): iterable
    {
        yield 'a Mastodon status on another server' => ['https://elsewhere.example/@alice/120', false];
        yield 'no Mastodon status' => ['https://mastodon.example/@alice', false];
        yield 'no Bluesky post' => ['https://bsky.app/profile/did:plc:alice', true];
    }
}
