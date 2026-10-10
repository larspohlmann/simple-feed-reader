<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\Model\ScrapeFallback;
use App\Tests\Service\Discovery\BuildsFeedDiscovery;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Proves discovery subscribes what the GitHub and YouTube resolvers answer; their edge cases are their own tests'. */
final class PlatformLinkDiscoveryTest extends KernelTestCase
{
    use BuildsFeedDiscovery;

    public function testARepositoryLinkSubscribesTheReleasesFeedWithoutFetchingThePage(): void
    {
        $feed = 'https://github.com/symfony/symfony/releases.atom';
        $fetcher = $this->fetcherReturning($feed, $feed, $this->rss2BasicXml());

        $result = $this->discovery($fetcher)
            ->discover('https://github.com/symfony/symfony/tree/7.4', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame($feed, $result->feed->url);
        self::assertSame([$feed], $fetcher->fetchedUrls);
    }

    public function testAVideoInsideAPlaylistSubscribesThePlaylist(): void
    {
        $feed = 'https://www.youtube.com/feeds/videos.xml?playlist_id=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF';
        $fetcher = $this->fetcherReturning($feed, $feed, $this->rss2BasicXml());

        $result = $this->discovery($fetcher)->discover(
            'https://www.youtube.com/watch?v=EeS-cBgIoxI&list=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF',
            ScrapeFallback::Enabled,
        );

        self::assertNotNull($result->feed);
        self::assertSame($feed, $result->feed->url);
        self::assertSame([$feed], $fetcher->fetchedUrls);
    }

    public function testAVideoLinkSubscribesItsChannel(): void
    {
        $feed = 'https://www.youtube.com/feeds/videos.xml?channel_id=UCZpc-xP3njReG_r4Ur5a7mA';
        $fetcher = $this->fetcherReturning($feed, $feed, $this->rss2BasicXml());
        $fetcher->willReturnBody(
            'https://www.youtube.com/watch?v=EeS-cBgIoxI',
            '<script>{"externalChannelId":"UCZpc-xP3njReG_r4Ur5a7mA"}</script>',
        );

        $result = $this->discovery($fetcher)->discover('https://youtu.be/EeS-cBgIoxI', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame($feed, $result->feed->url);
        self::assertSame(['https://www.youtube.com/watch?v=EeS-cBgIoxI', $feed], $fetcher->fetchedUrls);
    }
}
