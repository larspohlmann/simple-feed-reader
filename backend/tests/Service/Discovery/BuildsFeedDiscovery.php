<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Service\Discovery\BotChallengePage;
use App\Service\Discovery\FeedDiscovery\FeedDiscovery;
use App\Service\Discovery\FeedLinkScanner;
use App\Service\Discovery\SubstackProfileFeed;
use App\Service\Discovery\WellKnownFeedProbe;
use App\Service\Discovery\WordPressRestProbe;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Parser\FeedParser;
use App\Service\Scraper\HtmlItemExtractor;
use App\Tests\Support\StubFeedFetcher;

/** Wires a FeedDiscovery around a stub fetcher, so a test names the behaviour it exercises, not the constructor. */
trait BuildsFeedDiscovery
{
    private function discovery(StubFeedFetcher $fetcher): FeedDiscovery
    {
        $parser = self::getContainer()->get(FeedParser::class);
        self::assertInstanceOf(FeedParser::class, $parser);
        $extractor = self::getContainer()->get(HtmlItemExtractor::class);
        self::assertInstanceOf(HtmlItemExtractor::class, $extractor);

        return new FeedDiscovery(
            $fetcher,
            $parser,
            $extractor,
            new FeedLinkScanner(),
            new WellKnownFeedProbe($fetcher, $parser),
            new BotChallengePage(),
            new SubstackProfileFeed($fetcher),
            new WordPressRestProbe($fetcher),
        );
    }

    /**
     * A site serving only the URLs a test stubs: discovery guesses addresses, so a test says "nothing else is out
     * there" once instead of listing every guess.
     */
    private function fetcher(): StubFeedFetcher
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrowForEverythingElse(new FeedUnreachableException('x: HTTP 404', 404));

        return $fetcher;
    }

    private function fetcherReturning(string $url, string $finalUrl, string $body): StubFeedFetcher
    {
        $fetcher = $this->fetcher();
        $fetcher->willReturn(
            $url,
            FetchResponseModel::fetched(
                $finalUrl,
                permanentRedirect: false,
                body: $body,
                etag: null,
                lastModified: null,
            ),
        );

        return $fetcher;
    }
}
