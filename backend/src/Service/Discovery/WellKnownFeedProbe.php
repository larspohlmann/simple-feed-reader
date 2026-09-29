<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Service\Discovery\Model\DiscoveredFeedModel;
use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Pass\PageUrls;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;

/**
 * Looks for a feed under the conventional paths of a page that named none, for sites that refuse their HTML to
 * non-browsers but serve the feed (reddit refuses /r/<name>/, not /r/<name>/.rss). The guesses go out together over
 * the SSRF-guarded concurrent fetcher: one round trip, not seven timeouts.
 */
final readonly class WellKnownFeedProbe
{
    /**
     * The conventional feed paths, most likely first. `.rss` leads because it is
     * the convention of the site class this probe exists for; the rest are the
     * common CMS defaults.
     *
     * @var list<string>
     */
    private const array SUFFIXES = ['.rss', 'feed', 'rss', 'rss.xml', 'feed.xml', 'atom.xml', 'index.xml'];

    public function __construct(
        private BatchFeedFetcherInterface $fetcher,
        private FeedParser $parser,
    ) {
    }

    /**
     * The likeliest conventional path that serves a parseable feed, or null: no feed under a conventional path is the
     * ordinary case, not a failure.
     */
    public function probe(string $pageUrl): ?DiscoveredFeedModel
    {
        $candidateUrls = $this->candidateUrls(new PageUrls($pageUrl));
        if ([] === $candidateUrls) {
            return null;
        }

        $feeds = $this->feedsAmong($candidateUrls);

        // Preference, not arrival order: the outcomes come back as the hosts
        // answer, and `.rss` beating `index.xml` is the point of the list.
        foreach (array_keys($candidateUrls) as $rank) {
            if (isset($feeds[$rank])) {
                return $feeds[$rank];
            }
        }

        return null;
    }

    /**
     * Each candidate that served a feed, keyed by its rank in the suffix list.
     *
     * @param array<int, string> $candidateUrls
     *
     * @return array<int, DiscoveredFeedModel>
     */
    private function feedsAmong(array $candidateUrls): array
    {
        $tickets = array_map(
            static fn (string $url): FetchTicketModel => new FetchTicketModel($url),
            $candidateUrls,
        );

        $feeds = [];
        foreach ($this->fetcher->fetchAll($tickets) as $rank => $outcome) {
            $feed = $this->feedOf($outcome);
            if (null !== $feed) {
                $feeds[(int) $rank] = $feed;
            }
        }

        return $feeds;
    }

    private function feedOf(FetchOutcomeModel $outcome): ?DiscoveredFeedModel
    {
        if (null !== $outcome->failure()) {
            return null;
        }

        $response = $outcome->responseOrThrow();

        try {
            return new DiscoveredFeedModel(
                $response->finalUrl,
                $this->parser->parse($response->modifiedBody()),
                $response->etag,
                $response->lastModified,
            );
        } catch (FeedParseException) {
            return null;
        }
    }

    /**
     * One URL per suffix in preference order, or none for a URL with no host or one that already is a feed address
     * (refused, not missing: `/.rss/.rss` only adds load). The URL is a directory: RFC 3986 would resolve `.rss`
     * against `/r/Bitwig` as `/r/.rss`.
     *
     * @return array<int, string>
     */
    private function candidateUrls(PageUrls $pageUrls): array
    {
        $origin = $pageUrls->origin();
        if (null === $origin) {
            return [];
        }

        $path = $pageUrls->path();
        if (\in_array(basename($path), self::SUFFIXES, true)) {
            return [];
        }

        $directory = $origin . (str_ends_with($path, '/') ? $path : $path . '/');

        return array_map(static fn (string $suffix): string => $directory . $suffix, self::SUFFIXES);
    }
}
