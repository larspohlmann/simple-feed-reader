<?php

declare(strict_types=1);

namespace App\Service\Discovery\FeedDiscovery;

use App\Enum\SourceFormat;
use App\Service\Discovery\BotChallengePage;
use App\Service\Discovery\FeedLinkScanner;
use App\Service\Discovery\FeedOffer\FeedOfferInterface;
use App\Service\Discovery\Model\DiscoveredFeedModel;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Discovery\Model\FeedDiscoveryResultModel;
use App\Service\Discovery\Model\ScrapeFailureReason;
use App\Service\Discovery\Model\ScrapeFallback;
use App\Service\Discovery\ShareLinkFeed\ShareLinkFeedInterface;
use App\Service\Discovery\WellKnownFeedProbe;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use App\Service\Scraper\HtmlItemExtractor;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns an entered URL into something to subscribe to, most certain source first: the URL as a feed, the feeds the
 * page links, then the feeds it implies, a feed under a conventional path, and last a 'scraped' candidate from
 * the page.
 * Never throws for a bad address: a failure is a scrapeFailureReason, so the subscribe endpoint can always answer.
 */
final readonly class FeedDiscovery implements FeedDiscoveryInterface
{
    /**
     * Statuses meaning "the site answered but refused us" — retrying won't help,
     * a feed URL might. 429 is NOT one of them: retrying is exactly what that
     * one asks for, and it arrives as its own FeedThrottledException.
     */
    private const array BLOCKED_STATUSES = [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN];

    /**
     * @param iterable<ShareLinkFeedInterface> $shareLinks
     * @param iterable<FeedOfferInterface>     $offers
     */
    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
        private HtmlItemExtractor $extractor,
        private FeedLinkScanner $links,
        private WellKnownFeedProbe $wellKnownFeeds,
        private BotChallengePage $botChallenge,
        #[AutowireIterator('app.share_link_feed')]
        private iterable $shareLinks,
        #[AutowireIterator('app.feed_offer')]
        private iterable $offers,
    ) {
    }

    public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResultModel
    {
        $url = $this->shareLinkFeedUrl($url) ?? $url;

        try {
            $response = $this->fetcher->fetch($url);
        } catch (FeedThrottledException) {
            // The site has just asked us to slow down; the parallel guesses are
            // the opposite of that, and each would draw its own 429.
            return FeedDiscoveryResultModel::scrapeFailed(ScrapeFailureReason::Throttled);
        } catch (FeedUnreachableException $exception) {
            return $this->feedTheSiteMightStillServe($url, $exception);
        } catch (FetchException) {
            // Gone, over-size, SSRF-blocked: nothing usable ever arrived.
            return FeedDiscoveryResultModel::scrapeFailed(ScrapeFailureReason::Unreachable);
        }

        $body = $response->modifiedBody();

        try {
            $document = $this->parser->parse($body);

            return FeedDiscoveryResultModel::directFeed(new DiscoveredFeedModel(
                $response->finalUrl,
                $document,
                $response->etag,
                $response->lastModified,
            ));
        } catch (FeedParseException) {
            // Not a feed — treat it as a page that may point at one.
        }

        // Unless a gate answered for the site. Its page points at no feed and
        // scrapes to nothing, so every step below would end in "no feed here" —
        // which is the one thing this answer does not mean.
        if ($this->botChallenge->wasReturned($body)) {
            return FeedDiscoveryResultModel::scrapeFailed(ScrapeFailureReason::Blocked);
        }

        // The page's own advertised feeds lead, and the dialog opens the first one expanded.
        $candidates = $this->firstPerUrl([
            ...$this->links->scan($body, $response->finalUrl),
            ...$this->offered($body, $response->finalUrl),
        ]);

        return [] !== $candidates
            ? FeedDiscoveryResultModel::candidates($candidates)
            : $this->feedThePageNeverMentions($body, $response->finalUrl, $fallback);
    }

    private function shareLinkFeedUrl(string $enteredUrl): ?string
    {
        foreach ($this->shareLinks as $shareLink) {
            $feedUrl = $shareLink->feedUrl($enteredUrl);
            if (null !== $feedUrl) {
                return $feedUrl;
            }
        }

        return null;
    }

    /**
     * @param list<FeedCandidateModel> $candidates
     * @return list<FeedCandidateModel>
     */
    private function firstPerUrl(array $candidates): array
    {
        $byUrl = [];
        foreach ($candidates as $candidate) {
            $byUrl[$candidate->url] ??= $candidate;
        }

        return array_values($byUrl);
    }

    /** @return list<FeedCandidateModel> */
    private function offered(string $body, string $pageUrl): array
    {
        $candidates = [];
        foreach ($this->offers as $offer) {
            $candidate = $offer->offer($body, $pageUrl);
            if (null !== $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * The page arrived and points at no feed at all. Before synthesizing one
     * from its article list, ask for the conventional paths: a real feed the
     * page merely forgot to advertise beats anything the scraper can build.
     */
    private function feedThePageNeverMentions(
        string $body,
        string $finalUrl,
        ScrapeFallback $fallback,
    ): FeedDiscoveryResultModel {
        return $this->probedFeed($finalUrl)
            ?? (ScrapeFallback::Enabled === $fallback
                ? $this->scrapeFallback($body, $finalUrl)
                : FeedDiscoveryResultModel::candidates([]));
    }

    /**
     * The page did not arrive, but its site may still serve a feed under a conventional path. Only worth probing when
     * the site answered for itself: no status (DNS, a dead connection) or a 5xx would fail the guesses the same way.
     */
    private function feedTheSiteMightStillServe(
        string $url,
        FeedUnreachableException $error,
    ): FeedDiscoveryResultModel {
        $status = $error->statusCode;
        if (null === $status || $status >= Response::HTTP_INTERNAL_SERVER_ERROR) {
            return FeedDiscoveryResultModel::scrapeFailed(ScrapeFailureReason::Unreachable);
        }

        $reason = \in_array($status, self::BLOCKED_STATUSES, true)
            ? ScrapeFailureReason::Blocked
            : ScrapeFailureReason::Unreachable;

        return $this->probedFeed($url) ?? FeedDiscoveryResultModel::scrapeFailed($reason);
    }

    /**
     * A hit is reported as a direct feed rather than as a candidate: the probe
     * has already parsed the document, and a candidate would cost two more
     * requests (preview, then subscribe) to a host that just turned one down.
     */
    private function probedFeed(string $url): ?FeedDiscoveryResultModel
    {
        $probed = $this->wellKnownFeeds->probe($url);

        return null === $probed ? null : FeedDiscoveryResultModel::directFeed($probed);
    }

    /**
     * Offers the page itself as a 'scraped' candidate, but only once the extractor gets an article list out of it, so
     * no candidate's first refresh is bound to fail. Keyed by the final URL, which the subscribe then stores.
     */
    private function scrapeFallback(string $body, string $finalUrl): FeedDiscoveryResultModel
    {
        try {
            $parsed = $this->extractor->extract($body, $finalUrl);
        } catch (\Throwable) {
            // Deliberately wider than HtmlExtractionException: an extractor
            // bug on exotic markup must degrade to "not scrapable", not 500
            // the subscribe endpoint.
            return FeedDiscoveryResultModel::scrapeFailed(ScrapeFailureReason::NotScrapable);
        }

        return FeedDiscoveryResultModel::candidates([
            new FeedCandidateModel($finalUrl, $parsed->title, SourceFormat::SCRAPED),
        ]);
    }
}
