<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\SourceFormat;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\PageUrls;
use App\Service\Html\HtmlDocumentParser;
use Dom\HTMLDocument;

/**
 * Offers a WordPress REST posts endpoint as a richer alternative to a site's
 * RSS feed. Sibling of WellKnownFeedProbe, but it runs ALONGSIDE the link scan
 * rather than as a fallback: the whole point is to sit next to the RSS
 * candidate on a page that advertises both.
 *
 * The REST root is resolved in two tiers, both silent on absence:
 *   1. the canonical head link <link rel="https://api.w.org/">, or
 *   2. the default {origin}/wp-json/ — but only when the page body carries a
 *      WordPress fingerprint, so a non-WordPress page is never probed.
 * A resolved root is verified once through the SSRF-guarded fetcher: only a
 * non-empty JSON post array becomes a candidate, so a disabled or gated REST
 * API (the common reason the head link is stripped) simply offers nothing.
 */
final readonly class WordPressRestProbe
{
    private const string REST_ROOT_REL = 'https://api.w.org/';

    /** Substrings that mark a page as WordPress when the head link is absent. */
    private const array FINGERPRINTS = ['wp-content', 'wp-includes', 'content="WordPress'];

    private const int PER_PAGE = 50;

    public function __construct(private FeedFetcherInterface $fetcher)
    {
    }

    public function offer(string $body, string $pageUrl): ?FeedCandidate
    {
        $document = HtmlDocumentParser::parseOrNull($body);
        if (null === $document) {
            return null;
        }

        $pageUrls = new PageUrls($pageUrl);
        $root = $this->restRoot($document, $pageUrls, $body);
        $postsUrl = null === $root ? null : $this->postsUrl($root);
        if (null === $postsUrl || !$this->hasPosts($postsUrl)) {
            return null;
        }

        return new FeedCandidate($postsUrl, $this->pageTitle($document), SourceFormat::WP_JSON);
    }

    private function restRoot(HTMLDocument $document, PageUrls $pageUrls, string $body): ?string
    {
        $advertised = $this->advertisedRoot($document, $pageUrls);
        if (null !== $advertised) {
            return $advertised;
        }

        return $this->looksLikeWordPress($body) ? $pageUrls->origin() . '/wp-json/' : null;
    }

    private function advertisedRoot(HTMLDocument $document, PageUrls $pageUrls): ?string
    {
        foreach ($document->querySelectorAll('link[rel]') as $link) {
            if (self::REST_ROOT_REL === strtolower(trim($link->getAttribute('rel') ?? ''))) {
                return $pageUrls->httpUrl(trim($link->getAttribute('href') ?? ''));
            }
        }

        return null;
    }

    private function looksLikeWordPress(string $body): bool
    {
        foreach (self::FINGERPRINTS as $needle) {
            if (str_contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The posts URL under a pretty-permalink root. A `?rest_route=` root carries
     * a query, so appending a path and a second query cannot form a valid URL —
     * that install is left to its RSS feed.
     */
    private function postsUrl(string $root): ?string
    {
        if (str_contains($root, '?')) {
            return null;
        }

        return rtrim($root, '/') . '/wp/v2/posts?per_page=' . self::PER_PAGE . '&_embed';
    }

    private function hasPosts(string $postsUrl): bool
    {
        try {
            $response = $this->fetcher->fetch($postsUrl);
        } catch (FetchException) {
            // Gone, blocked, 401/403, SSRF-refused: no alternative to offer.
            return false;
        }

        $posts = json_decode($response->body ?? '', true);

        return \is_array($posts) && array_is_list($posts) && [] !== $posts;
    }

    private function pageTitle(HTMLDocument $document): ?string
    {
        $title = trim($document->querySelector('title')->textContent ?? '');

        return '' === $title ? null : $title;
    }
}
