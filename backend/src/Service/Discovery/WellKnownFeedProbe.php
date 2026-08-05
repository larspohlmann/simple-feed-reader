<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Service\Fetch\BatchFeedFetcherInterface;
use App\Service\Fetch\FetchOutcome;
use App\Service\Fetch\FetchTicket;
use App\Service\Fetch\UrlResolver;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;

/**
 * Looks for a feed under the conventional paths of a page that named none.
 *
 * Some sites serve their feed happily while answering their HTML page with 403
 * to anything that is not a browser — reddit.com is the archetype: /r/<name>/
 * is refused, /r/<name>/.rss is not. Discovery reads the page to find the feed,
 * so for those sites it has nothing to read and gives up on a feed that is one
 * request away. This guesses that request.
 *
 * The guesses go out together over the concurrent fetcher, so the walk costs
 * one round trip rather than six: a host that answers slowly must not hold the
 * subscribe request for six timeouts. They inherit that fetcher's SSRF guard,
 * and a guess that fails is not an error — a 404, a timeout and a body that is
 * not a feed are all just answers of "not here".
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
    private const array SUFFIXES = ['.rss', 'feed', 'rss', 'feed.xml', 'atom.xml', 'index.xml'];

    public function __construct(
        private BatchFeedFetcherInterface $fetcher,
        private FeedParser $parser,
    ) {
    }

    /**
     * The likeliest conventional path that answers with a parseable feed, with
     * the document it answered — or null when none does. Null is an absence,
     * not a failure signal: a site with no feed under a conventional path is
     * the ordinary case, and the caller reports the page's own outcome instead.
     */
    public function probe(string $pageUrl): ?DiscoveredFeed
    {
        $candidateUrls = $this->candidateUrls($pageUrl);
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
     * @return array<int, DiscoveredFeed>
     */
    private function feedsAmong(array $candidateUrls): array
    {
        $tickets = array_map(
            static fn (string $url): FetchTicket => new FetchTicket($url),
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

    private function feedOf(FetchOutcome $outcome): ?DiscoveredFeed
    {
        if (null !== $outcome->failure()) {
            return null;
        }

        $response = $outcome->responseOrThrow();

        try {
            return new DiscoveredFeed($response->finalUrl, $this->parser->parse($response->body ?? ''));
        } catch (FeedParseException) {
            return null;
        }
    }

    /**
     * One URL per conventional suffix, in preference order, or none at all when
     * there is nothing worth asking for: a URL with no host, or one that already
     * IS a feed address. The latter was refused rather than missing — a rate
     * limiter, typically — and `/.rss/.rss` can only add load.
     *
     * The entered URL is treated as a directory. That matters: a section address
     * is usually written without a trailing slash, and RFC 3986 resolves `.rss`
     * against `/r/Bitwig` as `/r/.rss`, probing the wrong level of the site.
     *
     * @return array<int, string>
     */
    private function candidateUrls(string $pageUrl): array
    {
        $origin = UrlResolver::origin($pageUrl);
        if (null === $origin) {
            return [];
        }

        $path = parse_url($pageUrl, \PHP_URL_PATH);
        $path = \is_string($path) && '' !== $path ? $path : '/';
        if (\in_array(basename($path), self::SUFFIXES, true)) {
            return [];
        }

        $directory = $origin . (str_ends_with($path, '/') ? $path : $path . '/');

        return array_map(static fn (string $suffix): string => $directory . $suffix, self::SUFFIXES);
    }
}
