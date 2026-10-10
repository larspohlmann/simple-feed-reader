<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Discovery\Model\FeedDiscoveryResultModel;
use App\Service\Discovery\Model\ScrapeFallback;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Tests\Service\Discovery\BuildsFeedDiscovery;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Proves discovery subscribes what the GitHub and YouTube links resolve to; their edge cases are their own tests'. */
final class PlatformLinkDiscoveryTest extends KernelTestCase
{
    use BuildsFeedDiscovery;

    private const string WATCH_PAGE = 'https://www.youtube.com/watch?v=EeS-cBgIoxI';

    private const string CHANNEL_FEED = 'https://www.youtube.com/feeds/videos.xml?channel_id=UCZpc-xP3njReG_r4Ur5a7mA';

    private const string WATCH_BODY = '<script>{"externalChannelId":"UCZpc-xP3njReG_r4Ur5a7mA"}</script>';

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
            self::WATCH_PAGE . '&list=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF',
            ScrapeFallback::Enabled,
        );

        self::assertNotNull($result->feed);
        self::assertSame($feed, $result->feed->url);
        self::assertSame([$feed], $fetcher->fetchedUrls);
    }

    public function testAVideoLinkOffersItsChannel(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willReturn('https://youtu.be/EeS-cBgIoxI', $this->watchPageReachedFrom());

        $result = $this->discovery($fetcher)->discover('https://youtu.be/EeS-cBgIoxI', ScrapeFallback::Enabled);

        self::assertNull($result->feed);
        self::assertSame([self::CHANNEL_FEED], $this->candidateUrls($result));
    }

    /** A mix has no feed (YouTube answers 404), so the link falls back to its watch page and offers the channel. */
    public function testAListWithoutAFeedFallsBackToTheVideosChannel(): void
    {
        $mixLink = self::WATCH_PAGE . '&list=RDEeS-cBgIoxI';
        $fetcher = $this->fetcher();
        $fetcher->willReturn($mixLink, $this->watchPageReachedFrom());

        $result = $this->discovery($fetcher)->discover($mixLink, ScrapeFallback::Enabled);

        self::assertSame([self::CHANNEL_FEED], $this->candidateUrls($result));
        self::assertSame(
            ['https://www.youtube.com/feeds/videos.xml?playlist_id=RDEeS-cBgIoxI', $mixLink],
            $fetcher->fetchedUrls,
        );
    }

    /** @return list<string> */
    private function candidateUrls(FeedDiscoveryResultModel $result): array
    {
        return array_map(static fn (FeedCandidateModel $candidate): string => $candidate->url, $result->candidates);
    }

    private function watchPageReachedFrom(): FetchResponseModel
    {
        return FetchResponseModel::fetched(
            self::WATCH_PAGE,
            permanentRedirect: false,
            body: self::WATCH_BODY,
            etag: null,
            lastModified: null,
        );
    }
}
