<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Fetch\Pass\PageUrls;
use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Scraper\Support\TextNormalizer;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Reads the feeds an HTML page points at. The strict pass reads the rss/atom autodiscovery links, whose type names
 * the dialect; only when it finds nothing does the fuzzy pass guess from vaguer types and feed-shaped anchors. A
 * wrong guess costs no subscription: the dialog previews every candidate.
 */
final readonly class FeedLinkScanner
{
    /** rel="alternate" types that name a feed document outright, mapped to the dialect they name. */
    private const array ADVERTISED_TYPES = [
        'application/rss+xml' => 'rss',
        'application/atom+xml' => 'atom',
    ];

    /**
     * rel="alternate" types a feed may carry without naming its dialect. No
     * JSON Feed type: nothing in Service/Parser reads one, so such a candidate
     * could only ever fail its preview.
     */
    private const array AMBIGUOUS_TYPES = ['text/xml', 'application/xml'];

    /**
     * The dialect of a guessed candidate is unknown until something parses it,
     * so it is offered as a plain 'feed' — the dialog renders that as "Feed".
     */
    private const string GUESSED_FORMAT = 'feed';

    /** A footer rarely hides more than a couple of feeds; the rest of a match list is noise. */
    private const int MAX_GUESSES = 5;

    /** A label is a card heading, not an article: anything longer is markup that leaked in. */
    private const int MAX_LABEL_CHARS = 120;

    /** `/feed`, `/rss/`, `/atom` — but not `/feedback`. */
    private const string FEED_PATH = '#/(feed|rss|atom)s?(/|$)#i';

    /** `/index.rss`, `/blog.atom`, `/feed.xml` — a feed-flavoured file name. */
    private const string FEED_FILE = '#\.(rss|atom)$|(rss|atom|feed)[^/]*\.xml$#i';

    /** `?feed=rss2`, `?format=atom` — the query-driven feeds of older CMSes. */
    private const string FEED_QUERY = '#(^|&)(feed|format|type)=(rss|atom|feed)#i';

    /** An anchor calling itself a feed, whatever its address looks like. */
    private const string FEED_LABEL = '#\b(rss|atom|feed)\b#i';

    /** @return list<FeedCandidateModel> */
    public function scan(string $html, string $baseUrl): array
    {
        $document = HtmlDocumentParser::parseOrEmpty($html);

        $pageUrls = new PageUrls($baseUrl);
        $advertised = $this->advertisedFeeds($document, $pageUrls);

        return [] !== $advertised ? $advertised : $this->feedShapedLinks($document, $pageUrls);
    }

    /** @return list<FeedCandidateModel> */
    private function advertisedFeeds(HTMLDocument $document, PageUrls $pageUrls): array
    {
        $candidates = [];
        foreach ($document->querySelectorAll('link[rel~="alternate" i][type]') as $link) {
            $format = self::ADVERTISED_TYPES[$this->attribute($link, 'type')] ?? null;
            $url = null === $format ? null : $this->subscribableUrl($link, $pageUrls);
            if (null === $url || null === $format || isset($candidates[$url])) {
                continue;
            }

            $candidates[$url] = new FeedCandidateModel($url, $this->label($link), $format);
        }

        return array_values($candidates);
    }

    /** @return list<FeedCandidateModel> */
    private function feedShapedLinks(HTMLDocument $document, PageUrls $pageUrls): array
    {
        $candidates = [];
        foreach ($document->querySelectorAll('link[rel~="alternate" i][type], a[href]') as $link) {
            $url = $this->subscribableUrl($link, $pageUrls);
            if (null === $url || isset($candidates[$url])) {
                continue;
            }

            $label = $this->label($link);
            if (!$this->looksLikeAFeed($link, $url, $label)) {
                continue;
            }

            $candidates[$url] = new FeedCandidateModel($url, $label, self::GUESSED_FORMAT);
            if (\count($candidates) === self::MAX_GUESSES) {
                break;
            }
        }

        return array_values($candidates);
    }

    private function looksLikeAFeed(Element $link, string $url, ?string $label): bool
    {
        if (\in_array($this->attribute($link, 'type'), self::AMBIGUOUS_TYPES, true)) {
            return true;
        }

        $parts = parse_url($url) ?: [];

        return 1 === preg_match(self::FEED_PATH, $parts['path'] ?? '')
            || 1 === preg_match(self::FEED_FILE, $parts['path'] ?? '')
            || 1 === preg_match(self::FEED_QUERY, $parts['query'] ?? '')
            || 1 === preg_match(self::FEED_LABEL, $label ?? '');
    }

    /**
     * Absolute http(s) URL of the link, or null when there is nothing to subscribe to. The page itself counts as
     * nothing: a "subscribe" anchor in a nav bar must not become a candidate that cannot work.
     */
    private function subscribableUrl(Element $link, PageUrls $pageUrls): ?string
    {
        $href = trim($link->getAttribute('href') ?? '');
        // A bare fragment addresses the page itself, which resolves to an
        // http(s) URL the check below would not catch: it differs from the page
        // by the fragment alone.
        if (str_starts_with($href, '#')) {
            return null;
        }

        $resolved = $pageUrls->httpUrl($href);

        return null === $resolved || $pageUrls->isPageItself($resolved) ? null : $resolved;
    }

    /** The link's own name: its title attribute, or the text a reader sees. */
    private function label(Element $link): ?string
    {
        foreach ([$link->getAttribute('title'), $link->getAttribute('aria-label'), $link->textContent] as $text) {
            $normalized = TextNormalizer::normalize((string) $text);
            if ('' !== $normalized) {
                return mb_substr($normalized, 0, self::MAX_LABEL_CHARS);
            }
        }

        return null;
    }

    private function attribute(Element $link, string $name): string
    {
        return strtolower(trim($link->getAttribute($name) ?? ''));
    }
}
