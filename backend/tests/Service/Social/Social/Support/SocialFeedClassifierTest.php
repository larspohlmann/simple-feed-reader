<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Entity\Feed;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Service\Social\Social\Support\SocialFeedClassifier;
use PHPUnit\Framework\TestCase;

final class SocialFeedClassifierTest extends TestCase
{
    private const string BLUESKY_PROFILE = 'https://bsky.app/profile/alice.bsky.social/rss';

    public function testAPrivateFeedCarriesItsAddress(): void
    {
        $feed = self::feed('social:mastodon:7:list:12', SourceFormat::MASTODON_API);

        $address = SocialFeedClassifier::privateAddressOf($feed);

        self::assertNotNull($address);
        self::assertSame(7, $address->accountId);
        self::assertSame(SocialFeedKind::UserList, $address->kind);
    }

    public function testAProfileFeedHasNoPrivateAddress(): void
    {
        $feed = self::feed(self::BLUESKY_PROFILE, SourceFormat::XML);

        self::assertNull(SocialFeedClassifier::privateAddressOf($feed));
    }

    private static function feed(string $url, string $sourceFormat): Feed
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);

        return $feed;
    }
}
