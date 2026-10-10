<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\FeedDiscovery\FeedDiscovery;
use App\Service\Discovery\Model\ScrapeFallback;
use App\Service\Discovery\ShareLinkFeed\ApplePodcastShowFeed;
use App\Service\Discovery\ShareLinkFeed\GitHubRepositoryFeed;
use App\Service\Discovery\ShareLinkFeed\ShareLinkFeedInterface;
use App\Service\Discovery\ShareLinkFeed\SubstackProfileFeed;
use App\Service\Discovery\ShareLinkFeed\YouTubePlaylistFeed;
use App\Tests\Service\Discovery\BuildsFeedDiscovery;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** An untagged interface leaves #[AutowireIterator] empty without an error; this reads what discovery really got. */
final class ShareLinkFeedsAreConsultedTest extends KernelTestCase
{
    use BuildsFeedDiscovery;

    public function testTheContainerHandsDiscoveryEveryShareLinkResolver(): void
    {
        $discovery = self::getContainer()->get(FeedDiscovery::class);
        self::assertInstanceOf(FeedDiscovery::class, $discovery);

        $shareLinks = (new \ReflectionProperty($discovery, 'shareLinks'))->getValue($discovery);
        self::assertIsIterable($shareLinks);

        $classes = [];
        foreach ($shareLinks as $shareLink) {
            self::assertInstanceOf(ShareLinkFeedInterface::class, $shareLink);
            $classes[] = $shareLink::class;
        }

        self::assertContains(SubstackProfileFeed::class, $classes);
        self::assertContains(ApplePodcastShowFeed::class, $classes);
        self::assertContains(GitHubRepositoryFeed::class, $classes);
        self::assertContains(YouTubePlaylistFeed::class, $classes);
    }

    public function testTheFirstResolverToAnswerWinsAndTheRestAreNotAsked(): void
    {
        $fetcher = $this->fetcherReturning(
            'https://first.example/feed',
            'https://first.example/feed',
            $this->rss2BasicXml(),
        );
        $second = $this->answering(null);

        $result = $this->discoveryResolving($fetcher, [$this->answering('https://first.example/feed'), $second])
            ->discover('https://share.example/show', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame('https://first.example/feed', $result->feed->url);
        self::assertSame([], $second->asked);
    }

    public function testWhenNoResolverAnswersTheEnteredUrlIsFetched(): void
    {
        $fetcher = $this->fetcherReturning(
            'https://plain.example/feed',
            'https://plain.example/feed',
            $this->rss2BasicXml(),
        );

        $result = $this->discoveryResolving($fetcher, [$this->answering(null), $this->answering(null)])
            ->discover('https://plain.example/feed', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame(['https://plain.example/feed'], $fetcher->fetchedUrls);
    }

    public function testAResolvedAddressServingNoFeedFallsBackToTheEnteredUrl(): void
    {
        $fetcher = $this->fetcherReturning(
            'https://plain.example/feed',
            'https://plain.example/feed',
            $this->rss2BasicXml(),
        );
        $fetcher->willReturnBody('https://share.example/guess', '<html><body>Not a feed</body></html>');

        $result = $this->discoveryResolving($fetcher, [$this->answering('https://share.example/guess')])
            ->discover('https://plain.example/feed', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame('https://plain.example/feed', $result->feed->url);
        self::assertSame(['https://share.example/guess', 'https://plain.example/feed'], $fetcher->fetchedUrls);
    }

    public function testAnUnreachableResolvedAddressFallsBackToTheEnteredUrl(): void
    {
        $fetcher = $this->fetcherReturning(
            'https://plain.example/feed',
            'https://plain.example/feed',
            $this->rss2BasicXml(),
        );

        $result = $this->discoveryResolving($fetcher, [$this->answering('https://share.example/missing')])
            ->discover('https://plain.example/feed', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame('https://plain.example/feed', $result->feed->url);
    }

    /** @return ShareLinkFeedInterface&object{asked: list<string>} */
    private function answering(?string $feedUrl): ShareLinkFeedInterface
    {
        return new class ($feedUrl) implements ShareLinkFeedInterface {
            /** @var list<string> */
            public array $asked = [];

            public function __construct(private readonly ?string $feedUrl)
            {
            }

            public function feedUrl(string $enteredUrl): ?string
            {
                $this->asked[] = $enteredUrl;

                return $this->feedUrl;
            }
        };
    }
}
