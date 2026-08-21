<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Service\Discovery\BotChallengePage;
use App\Service\Discovery\FeedDiscovery;
use App\Service\Discovery\FeedLinkScanner;
use App\Service\Discovery\SubstackProfileFeed;
use App\Service\Discovery\WellKnownFeedProbe;
use App\Service\Discovery\WordPressRestProbe;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FetchResponse;
use App\Service\Parser\FeedParser;
use App\Service\Scraper\HtmlItemExtractor;
use App\Tests\Support\StubFeedFetcher;

/**
 * Wires a FeedDiscovery around a stub fetcher — the whole collaborator graph in
 * one place, so a test names only the behaviour it exercises, not the
 * constructor. The platform-specific SubstackProfileFeed is one of those
 * collaborators; it lives here, at the single wiring point that mirrors the
 * real container, and so stays out of the generic discovery test.
 */
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
     * A site that serves nothing but the URLs a test stubs. Discovery guesses
     * feed addresses now, so a test cannot list every URL it will ask for
     * without re-deriving the code under test; it says "nothing else is out
     * there" once instead.
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
            FetchResponse::fetched($finalUrl, permanentRedirect: false, body: $body, etag: null, lastModified: null),
        );

        return $fetcher;
    }
}
