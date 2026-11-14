<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Entity\Feed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Social\Support\SocialFeedClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialFeedClassifierTest extends TestCase
{
    private const string BLUESKY_PROFILE = 'https://bsky.app/profile/alice.bsky.social/rss';

    /** @return iterable<string, array{string, string, SocialNetwork|null}> */
    public static function feeds(): iterable
    {
        $bluesky = SocialNetwork::Bluesky;
        $mastodon = SocialNetwork::Mastodon;
        yield 'a Bluesky private feed' => ['social:bluesky:4:home', SourceFormat::BLUESKY_API, $bluesky];
        yield 'a Mastodon private feed' => ['social:mastodon:7:list:12', SourceFormat::MASTODON_API, $mastodon];
        yield 'a Bluesky profile feed' => [self::BLUESKY_PROFILE, SourceFormat::XML, $bluesky];
        yield 'a Mastodon profile feed' => ['https://mastodon.social/@Gargron.rss', SourceFormat::XML, $mastodon];
        yield 'a synthetic URL under a plain format' => ['social:bluesky:4:home', SourceFormat::XML, null];
        yield 'a malformed synthetic URL' => ['social:bluesky:x:home', SourceFormat::BLUESKY_API, null];
        yield 'an ordinary feed' => ['https://example.com/feed.xml', SourceFormat::XML, null];
    }

    #[DataProvider('feeds')]
    public function testItNamesTheNetworkAFeedBelongsTo(
        string $url,
        string $sourceFormat,
        ?SocialNetwork $network,
    ): void {
        self::assertSame($network, SocialFeedClassifier::networkOf(self::feed($url, $sourceFormat)));
    }

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

    #[DataProvider('feeds')]
    public function testAStoredRowsUrlAndFormatNameTheSameNetwork(
        string $url,
        string $sourceFormat,
        ?SocialNetwork $network,
    ): void {
        self::assertSame($network, SocialFeedClassifier::networkFor($url, $sourceFormat));
    }
}
