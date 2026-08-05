<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Service\Fetch\UrlResolver;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Reads the feeds an HTML page points at.
 *
 * Two passes, and the first one that finds anything wins. The strict pass reads
 * the autodiscovery links a well-behaved page publishes — `<link rel="alternate"
 * type="application/rss+xml">` and its Atom twin — and its result is exact: the
 * type attribute names the dialect.
 *
 * The fuzzy pass exists because a large part of the web never got that memo. It
 * runs only when the strict pass found nothing, and it guesses: an `<link
 * rel="alternate">` carrying a vaguer type, or an ordinary `<a>` whose address
 * or label looks like a feed — the RSS icon in a footer. A guess costs no
 * request here; the dialog previews every candidate it is offered, so a wrong
 * guess shows up as an unavailable preview rather than as a bad subscription.
 */
final readonly class FeedLinkScanner
{
    /** rel="alternate" types that name a feed document outright, mapped to the dialect they name. */
    private const array ADVERTISED_TYPES = [
        'application/rss+xml' => 'rss',
        'application/atom+xml' => 'atom',
    ];

    /** rel="alternate" types a feed may carry without naming its dialect. */
    private const array AMBIGUOUS_TYPES = [
        'text/xml',
        'application/xml',
        'application/feed+json',
    ];

    /**
     * The dialect of a guessed candidate is unknown until something parses it,
     * so it is offered as a plain 'feed' — the dialog renders that as "Feed".
     */
    private const string GUESSED_FORMAT = 'feed';

    /** A footer rarely hides more than a couple of feeds; the rest of a match list is noise. */
    private const int MAX_GUESSES = 5;

    /** `/feed`, `/rss/`, `/atom` — but not `/feedback`. */
    private const string FEED_PATH = '#/(feed|rss|atom)s?(/|$)#i';

    /** `/index.rss`, `/blog.atom`, `/feed.xml` — a feed-flavoured file name. */
    private const string FEED_FILE = '#\.(rss|atom)$|(rss|atom|feed)[^/]*\.xml$#i';

    /** `?feed=rss2`, `?format=atom` — the query-driven feeds of older CMSes. */
    private const string FEED_QUERY = '#(^|&)(feed|format|type)=(rss|atom|feed)#i';

    /** An anchor calling itself a feed, whatever its address looks like. */
    private const string FEED_LABEL = '#\b(rss|atom|feed)\b#i';

    /** @return list<FeedCandidate> */
    public function scan(string $html, string $baseUrl): array
    {
        $document = $this->parse($html);
        if (null === $document) {
            return [];
        }

        $advertised = $this->advertisedFeeds($document, $baseUrl);

        return [] !== $advertised ? $advertised : $this->feedShapedLinks($document, $baseUrl);
    }

    private function parse(string $html): ?HTMLDocument
    {
        if ('' === trim($html)) {
            return null;
        }

        try {
            // The HTML parser resolves no entities and opens no connections, so
            // it needs no LIBXML_NONET — which it rejects as an invalid flag.
            return HTMLDocument::createFromString($html, \LIBXML_NOERROR);
        } catch (\Throwable) {
            // A page too broken to parse advertises nothing, which is an answer
            // discovery can work with — the scrape fallback runs next.
            return null;
        }
    }

    /** @return list<FeedCandidate> */
    private function advertisedFeeds(HTMLDocument $document, string $baseUrl): array
    {
        $candidates = [];
        foreach ($document->querySelectorAll('link[rel~="alternate" i][type]') as $link) {
            $format = self::ADVERTISED_TYPES[$this->attribute($link, 'type')] ?? null;
            if (null === $format) {
                continue;
            }

            $candidates[] = $this->candidate($link, $baseUrl, $format);
        }

        return $this->unique($candidates, $baseUrl);
    }

    /** @return list<FeedCandidate> */
    private function feedShapedLinks(HTMLDocument $document, string $baseUrl): array
    {
        $candidates = [];
        foreach ($document->querySelectorAll('link[rel~="alternate" i][type], a[href]') as $link) {
            if (!$this->looksLikeAFeed($link, $baseUrl)) {
                continue;
            }

            $candidates[] = $this->candidate($link, $baseUrl, self::GUESSED_FORMAT);
        }

        return \array_slice($this->unique($candidates, $baseUrl), 0, self::MAX_GUESSES);
    }

    private function looksLikeAFeed(Element $link, string $baseUrl): bool
    {
        if (\in_array($this->attribute($link, 'type'), self::AMBIGUOUS_TYPES, true)) {
            return true;
        }

        $url = $this->resolvedUrl($link, $baseUrl);
        if (null === $url) {
            return false;
        }

        $parts = parse_url($url);
        $path = \is_array($parts) ? ($parts['path'] ?? '') : '';
        $query = \is_array($parts) ? ($parts['query'] ?? '') : '';

        return 1 === preg_match(self::FEED_PATH, $path)
            || 1 === preg_match(self::FEED_FILE, $path)
            || 1 === preg_match(self::FEED_QUERY, $query)
            || 1 === preg_match(self::FEED_LABEL, $this->label($link) ?? '');
    }

    private function candidate(Element $link, string $baseUrl, string $format): ?FeedCandidate
    {
        $url = $this->resolvedUrl($link, $baseUrl);

        return null === $url ? null : new FeedCandidate($url, $this->label($link), $format);
    }

    /**
     * Absolute http(s) URL of the link, or null when there is nothing to fetch:
     * an empty href, a `javascript:` or `mailto:` action, a bare fragment.
     */
    private function resolvedUrl(Element $link, string $baseUrl): ?string
    {
        $href = trim($link->getAttribute('href') ?? '');
        if ('' === $href || str_starts_with($href, '#')) {
            return null;
        }

        $resolved = UrlResolver::resolve($baseUrl, $href);

        return 1 === preg_match('#^https?://#i', $resolved) ? $resolved : null;
    }

    /** The link's own name: its title attribute, or the text a reader sees. */
    private function label(Element $link): ?string
    {
        foreach ([$link->getAttribute('title'), $link->getAttribute('aria-label'), $link->textContent] as $text) {
            $trimmed = trim((string) $text);
            if ('' !== $trimmed) {
                return mb_substr($trimmed, 0, 120);
            }
        }

        return null;
    }

    private function attribute(Element $link, string $name): string
    {
        return strtolower(trim($link->getAttribute($name) ?? ''));
    }

    /**
     * Drops the unresolvable links, the duplicates and any link back to the
     * page being scanned — offering the page itself as its own feed is how a
     * "subscribe" anchor in a nav bar turns into a candidate that cannot work.
     *
     * @param list<FeedCandidate|null> $candidates
     *
     * @return list<FeedCandidate>
     */
    private function unique(array $candidates, string $baseUrl): array
    {
        $byUrl = [];
        foreach ($candidates as $candidate) {
            if (null === $candidate || $candidate->url === $baseUrl || isset($byUrl[$candidate->url])) {
                continue;
            }
            $byUrl[$candidate->url] = $candidate;
        }

        return array_values($byUrl);
    }
}
