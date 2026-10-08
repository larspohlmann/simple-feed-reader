<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\FeedDiscovery\FeedDiscovery;
use App\Service\Discovery\Model\ScrapeFallback;
use App\Service\Discovery\ShareLinkFeed\ShareLinkFeedInterface;
use App\Service\Discovery\ShareLinkFeed\SubstackProfileFeed;
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
    }

    public function testTheFirstResolverToAnswerWinsAndTheRestAreNotAsked(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);
        $fetcher = $this->fetcherReturning('https://first.example/feed', 'https://first.example/feed', $xml);
        $second = new class implements ShareLinkFeedInterface {
            /** @var list<string> */
            public array $asked = [];

            public function feedUrl(string $enteredUrl): ?string
            {
                $this->asked[] = $enteredUrl;

                return null;
            }
        };

        $result = $this->discoveryResolving($fetcher, [$this->answering('https://first.example/feed'), $second])
            ->discover('https://share.example/show', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame('https://first.example/feed', $result->feed->url);
        self::assertSame([], $second->asked);
    }

    public function testWhenNoResolverAnswersTheEnteredUrlIsFetched(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);
        $fetcher = $this->fetcherReturning('https://plain.example/feed', 'https://plain.example/feed', $xml);

        $result = $this->discoveryResolving($fetcher, [$this->answering(null), $this->answering(null)])
            ->discover('https://plain.example/feed', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame(['https://plain.example/feed'], $fetcher->fetchedUrls);
    }

    private function answering(?string $feedUrl): ShareLinkFeedInterface
    {
        return new readonly class ($feedUrl) implements ShareLinkFeedInterface {
            public function __construct(private ?string $feedUrl)
            {
            }

            public function feedUrl(string $enteredUrl): ?string
            {
                return $this->feedUrl;
            }
        };
    }
}
