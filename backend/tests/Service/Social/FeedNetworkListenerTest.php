<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Tests\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FeedNetworkListenerTest extends DbTestCase
{
    /** @return iterable<string, array{string, string, SocialNetwork|null}> */
    public static function newFeeds(): iterable
    {
        yield 'a Bluesky private feed' => [
            'social:bluesky:4:home',
            SourceFormat::BLUESKY_API,
            SocialNetwork::Bluesky,
        ];
        yield 'a Mastodon private feed' => [
            'social:mastodon:7:list:12',
            SourceFormat::MASTODON_API,
            SocialNetwork::Mastodon,
        ];
        yield 'a Bluesky profile feed' => [
            'https://bsky.app/profile/alice.bsky.social/rss',
            SourceFormat::XML,
            SocialNetwork::Bluesky,
        ];
        yield 'a Mastodon profile feed' => [
            'https://mastodon.social/@Gargron.rss',
            SourceFormat::XML,
            SocialNetwork::Mastodon,
        ];
        yield 'an ordinary feed' => ['https://example.com/feed.xml', SourceFormat::XML, null];
    }

    #[DataProvider('newFeeds')]
    public function testANewFeedIsStoredWithItsNetwork(string $url, string $sourceFormat, ?SocialNetwork $network): void
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        self::assertSame($network, $this->storedNetworkOf($feed->requireId()));
    }

    public function testAProfileFeedRedirectedToAnOrdinaryUrlLosesItsNetwork(): void
    {
        $feedId = $this->storedFeed('https://bsky.app/profile/alice.bsky.social/rss', SourceFormat::XML);
        $reloaded = $this->entityManager->find(Feed::class, $feedId);
        self::assertNotNull($reloaded);

        $reloaded->setUrl('https://alice.example.com/feed.xml');
        $this->entityManager->flush();

        self::assertNull($this->storedNetworkOf($feedId));
    }

    public function testAnOrdinaryFeedRedirectedToAProfileFeedGainsItsNetwork(): void
    {
        $feedId = $this->storedFeed('https://example.com/gargron.xml', SourceFormat::XML);
        $reloaded = $this->entityManager->find(Feed::class, $feedId);
        self::assertNotNull($reloaded);

        $reloaded->setUrl('https://mastodon.social/@Gargron.rss');
        $this->entityManager->flush();

        self::assertSame(SocialNetwork::Mastodon, $this->storedNetworkOf($feedId));
    }

    public function testAFeedWhoseFormatBecomesPrivateGainsItsNetwork(): void
    {
        $feedId = $this->storedFeed('social:bluesky:4:home', SourceFormat::XML);
        self::assertNull($this->storedNetworkOf($feedId));
        $reloaded = $this->entityManager->find(Feed::class, $feedId);
        self::assertNotNull($reloaded);

        $reloaded->setSourceFormat(SourceFormat::BLUESKY_API);
        $this->entityManager->flush();

        self::assertSame(SocialNetwork::Bluesky, $this->storedNetworkOf($feedId));
    }

    private function storedFeed(string $url, string $sourceFormat): int
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();
        $this->entityManager->clear();

        return $feed->requireId();
    }

    private function storedNetworkOf(int $feedId): ?SocialNetwork
    {
        $this->entityManager->clear();
        $stored = $this->entityManager->find(Feed::class, $feedId);
        self::assertNotNull($stored);

        return $stored->getNetwork();
    }
}
