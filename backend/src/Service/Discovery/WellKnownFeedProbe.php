<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;

/**
 * Looks for a feed under the conventional paths of a page that refused us.
 *
 * Some sites serve their feed happily while answering their HTML page with 403
 * to anything that is not a browser — reddit.com is the archetype: /r/<name>/
 * is refused, /r/<name>/.rss is not. Discovery reads the page to find the feed,
 * so for those sites it has nothing to read and gives up on a feed that is one
 * request away. This guesses that request.
 *
 * Every probe goes through the SSRF-guarded fetcher, so the guesses inherit the
 * same protection as any other outbound request. A guess that fails is not an
 * error: a 404, a timeout and a body that is not a feed all just move on to the
 * next suffix.
 */
final readonly class WellKnownFeedProbe
{
    /**
     * The conventional feed paths, most likely first. `.rss` leads because it is
     * the convention of the site class this probe exists for; the rest are the
     * common CMS defaults. Public so the tests can assert the whole walk without
     * restating the list.
     *
     * @var list<string>
     */
    public const array SUFFIXES = ['.rss', 'feed', 'rss', 'feed.xml', 'atom.xml', 'index.xml'];

    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
    ) {
    }

    /**
     * The canonical URL of the first conventional path that answers with a
     * parseable feed, or null when none does. Null is an absence, not a failure
     * signal: a site with no feed under a conventional path is the ordinary
     * case, and the caller reports the site's own refusal instead.
     */
    public function probe(string $pageUrl): ?string
    {
        $directory = $this->directoryUrl($pageUrl);
        if (null === $directory) {
            return null;
        }

        foreach (self::SUFFIXES as $suffix) {
            $feedUrl = $this->feedUrlAt($directory . $suffix);
            if (null !== $feedUrl) {
                return $feedUrl;
            }
        }

        return null;
    }

    /** The final URL of $url when it serves a feed, null when it does not. */
    private function feedUrlAt(string $url): ?string
    {
        try {
            $response = $this->fetcher->fetch($url);
            $this->parser->parse($response->body ?? '');

            return $response->finalUrl;
        } catch (FetchException | FeedParseException) {
            return null;
        }
    }

    /**
     * The entered URL as a directory to append a suffix to — query and fragment
     * dropped, a trailing slash added. The slash matters: a section address is
     * usually written without one, and RFC 3986 would resolve `.rss` against
     * `/r/Bitwig` as `/r/.rss`, probing the wrong level of the site.
     *
     * Null when there is nothing to probe: a URL with no host, or one that
     * already IS a feed address. The latter was refused rather than missing —
     * a rate limiter, typically — and `/.rss/.rss` can only add load.
     */
    private function directoryUrl(string $pageUrl): ?string
    {
        $parts = parse_url($pageUrl);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        if (\in_array(basename($path), self::SUFFIXES, true)) {
            return null;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $origin . (str_ends_with($path, '/') ? $path : $path . '/');
    }
}
