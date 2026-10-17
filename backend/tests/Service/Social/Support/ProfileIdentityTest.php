<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Support\ProfileIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileIdentityTest extends TestCase
{
    #[DataProvider('feeds')]
    public function testAFeedFollowsThePersonItsAddressNames(
        SocialNetwork $network,
        string $feedUrl,
        ?string $identity,
    ): void {
        self::assertSame($identity, ProfileIdentity::of($network, $feedUrl));
    }

    /** @return iterable<string, array{SocialNetwork, string, ?string}> */
    public static function feeds(): iterable
    {
        yield 'a profile rss feed' => [
            SocialNetwork::Bluesky,
            'https://bsky.app/profile/did:plc:bob/rss',
            'did:plc:bob',
        ];
        yield 'an author feed' => [SocialNetwork::Bluesky, 'social:bluesky:3:author:did:plc:rusty', 'did:plc:rusty'];
        yield 'a home timeline' => [SocialNetwork::Bluesky, 'social:bluesky:3:home', null];
        yield 'an ordinary feed' => [SocialNetwork::Bluesky, 'https://example.com/feed.xml', null];
    }
}
