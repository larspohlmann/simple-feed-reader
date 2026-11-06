<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\Support\OwnInstancePostUrl;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class OwnInstancePostUrlTest extends TestCase
{
    private const string POST = 'https://mastodon.social/@Gargron/109';
    private const string FOREIGN_URL =
        'https://home.example/authorize_interaction?uri=https%3A%2F%2Fmastodon.social%2F%40Gargron%2F109';

    public function testAForeignPostOpensThroughTheReadersInstance(): void
    {
        self::assertSame(
            self::FOREIGN_URL,
            OwnInstancePostUrl::of($this->mastodonPost(), [SocialAccounts::mastodonOn('https://home.example')]),
        );
    }

    public function testATrailingSlashOnTheInstanceIsNotDoubled(): void
    {
        self::assertSame(
            self::FOREIGN_URL,
            OwnInstancePostUrl::of($this->mastodonPost(), [SocialAccounts::mastodonOn('https://home.example/')]),
        );
    }

    public function testAPostOnTheReadersOwnInstanceKeepsItsPlainLinkWhateverItsCase(): void
    {
        self::assertNull(
            OwnInstancePostUrl::of($this->mastodonPost(), [SocialAccounts::mastodonOn('https://Mastodon.Social')]),
        );
    }

    public function testTheHeadOfTheListActs(): void
    {
        $accounts = [
            SocialAccounts::mastodonOn('https://home.example'),
            SocialAccounts::mastodonOn('https://other.example'),
        ];

        self::assertSame(self::FOREIGN_URL, OwnInstancePostUrl::of($this->mastodonPost(), $accounts));
    }

    public function testABlueskyPostKeepsItsPlainLink(): void
    {
        $post = new SocialPostAddressModel(SocialNetwork::Bluesky, 'at://did:plc:alice/app.bsky.feed.post/3kabc');

        self::assertNull(OwnInstancePostUrl::of($post, [SocialAccounts::mastodonOn('https://home.example')]));
    }

    public function testWithoutAnAccountThePostKeepsItsPlainLink(): void
    {
        self::assertNull(OwnInstancePostUrl::of($this->mastodonPost(), []));
    }

    private function mastodonPost(): SocialPostAddressModel
    {
        return new SocialPostAddressModel(SocialNetwork::Mastodon, self::POST);
    }
}
