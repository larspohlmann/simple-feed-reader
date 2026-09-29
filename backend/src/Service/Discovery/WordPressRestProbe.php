<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\SourceFormat;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\Pass\PageUrls;
use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Scraper\Support\TextNormalizer;
use Dom\HTMLDocument;

/**
 * Offers a WordPress REST posts endpoint beside a site's RSS candidate. The REST root is the head link
 * `rel="https://api.w.org/"`, else `{origin}/wp-json/` on a page with a WordPress fingerprint; only a root whose
 * posts endpoint answers a non-empty JSON array becomes a candidate.
 */
final readonly class WordPressRestProbe
{
    private const string REST_ROOT_REL = 'https://api.w.org/';

    /** Substrings that mark a page as WordPress when the head link is absent. */
    private const array FINGERPRINTS = ['wp-content', 'wp-includes', 'content="WordPress'];

    private const int PER_PAGE = 20;

    /**
     * Only the fields the parser reads. Never `_embed`: it adds about 1.3 MB per post on large sites, so a page of
     * posts misses the fetcher's timeout and size cap; the image comes from `jetpack_featured_media_url` instead.
     */
    private const string FIELDS = 'id,date_gmt,link,guid,title,content,excerpt,jetpack_featured_media_url';

    public function __construct(private FeedFetcherInterface $fetcher)
    {
    }

    public function offer(string $body, string $pageUrl): ?FeedCandidateModel
    {
        $document = HtmlDocumentParser::parseOrEmpty($body);

        $pageUrls = new PageUrls($pageUrl);
        $postsUrl = $this->postsUrl($this->restRoot($document, $pageUrls, $body));
        if (null === $postsUrl || !$this->hasPosts($postsUrl)) {
            return null;
        }

        return new FeedCandidateModel($postsUrl, $this->pageTitle($document), SourceFormat::WP_JSON);
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
    private function postsUrl(?string $root): ?string
    {
        if (null === $root || str_contains($root, '?')) {
            return null;
        }

        return rtrim($root, '/') . '/wp/v2/posts?per_page=' . self::PER_PAGE . '&_fields=' . self::FIELDS;
    }

    private function hasPosts(string $postsUrl): bool
    {
        try {
            $response = $this->fetcher->fetch($postsUrl);
        } catch (FetchException) {
            // Gone, blocked, 401/403, SSRF-refused: no alternative to offer.
            return false;
        }

        $posts = json_decode($response->modifiedBody(), true);

        return \is_array($posts) && array_is_list($posts) && [] !== $posts;
    }

    private function pageTitle(HTMLDocument $document): ?string
    {
        $title = TextNormalizer::normalize($document->querySelector('title')->textContent ?? '');

        return '' === $title ? null : $title;
    }
}
