<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Discovery\Model\FeedDiscoveryResultModel;
use App\Service\Discovery\Model\ScrapeFallback;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Tests\Service\Discovery\BuildsFeedDiscovery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Proves discovery subscribes what a share link resolves to; each resolver's edge cases are its own test's. */
final class PlatformLinkDiscoveryTest extends KernelTestCase
{
    use BuildsFeedDiscovery;

    private const string WATCH_PAGE = 'https://www.youtube.com/watch?v=EeS-cBgIoxI';

    private const string CHANNEL_FEED = 'https://www.youtube.com/feeds/videos.xml?channel_id=UCZpc-xP3njReG_r4Ur5a7mA';

    private const string WATCH_BODY = '<script>{"externalChannelId":"UCZpc-xP3njReG_r4Ur5a7mA"}</script>';

    #[DataProvider('shareLinks')]
    public function testAShareLinkSubscribesItsFeedWithoutFetchingThePage(string $enteredUrl, string $feed): void
    {
        $fetcher = $this->fetcherReturning($feed, $feed, $this->rss2BasicXml());

        $result = $this->discovery($fetcher)->discover($enteredUrl, ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame($feed, $result->feed->url);
        self::assertSame([$feed], $fetcher->fetchedUrls);
    }

    /** @return iterable<string, array{string, string}> */
    public static function shareLinks(): iterable
    {
        yield 'a repository page' => [
            'https://github.com/symfony/symfony/tree/7.4',
            'https://github.com/symfony/symfony/releases.atom',
        ];
        yield 'a remote fediverse profile' => [
            'https://systemli.social/@linuzifer@23.social',
            'https://23.social/@linuzifer.rss',
        ];
        yield 'a video inside a playlist' => [
            self::WATCH_PAGE . '&list=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF',
            'https://www.youtube.com/feeds/videos.xml?playlist_id=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF',
        ];
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
