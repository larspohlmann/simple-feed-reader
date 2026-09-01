<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\Media\PageMediaScanner;
use App\Service\Sanitize\EntrySanitizer;
use Dom\HTMLDocument;
use fivefilters\Readability\Article;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;

/**
 * Turns an article URL into clean, sanitized, distraction-free HTML:
 * fetch (SSRF-guarded) → page normalization → page-media scan → readability
 * extraction → body cleaning (duplicate-title removal, edge-boilerplate
 * trimming, lead-image restore, media insertion) → EntrySanitizer (the same
 * XSS barrier feed HTML crosses). Never throws for an ordinary failure —
 * returns a `failed` ExtractionResult with a machine reason so the endpoint
 * stays 200 and the client can fall back to feed content.
 *
 * Readability strips a page-header image as chrome and reports it apart as the
 * og:image. ReaderBodyCleaner restores that picture into the extracted body via
 * ReaderLeadImage, when the page draws it and the body does not already show it
 * (#681). The "does the page draw it?" answer is a PageImageInventory this class
 * builds once from the normalised page document, before readability consumes it
 * (#684).
 *
 * PageMediaScanner also runs on the raw page before readability, so recovered
 * media can both satisfy the length gate below and be inserted by
 * ReaderBodyCleaner even when readability's own extraction is thin (#748).
 */
final class ArticleExtractor implements ArticleExtractorInterface
{
    /** Below this many characters of extracted text, treat as not an article. */
    private const int MIN_CONTENT_LENGTH = 200;

    public function __construct(
        private readonly HtmlPageFetcher $fetcher,
        private readonly FetchedPageNormalizer $normalizer,
        private readonly ReaderBodyCleaner $bodyCleaner,
        private readonly EntrySanitizer $sanitizer,
        private readonly PageMediaScanner $mediaScanner,
    ) {
    }

    public function extract(string $url, ?string $entryTitle = null, ?string $entryAuthor = null): ExtractionResult
    {
        try {
            $page = $this->fetcher->fetch($url);
        } catch (PageFetchException) {
            return ExtractionResult::failed($url, 'fetch');
        }

        $normalized = $this->normalizer->normalize($page->html);
        $pageImages = PageImageInventory::fromDocument($normalized);
        $media = $this->mediaScanner->scan($page->html, $page->finalUrl);

        $article = $this->richestArticle($normalized, $page);
        if ($article === null) {
            return ExtractionResult::failed($url, 'unextractable');
        }

        if ($article->content === null || !$article->hasContent()) {
            return ExtractionResult::failed($url, 'empty');
        }
        // A page whose media IS the article carries little prose. Recovered media
        // is itself evidence that this is an article worth showing.
        if ($media->isEmpty() && mb_strlen(trim((string) $article->textContent)) < self::MIN_CONTENT_LENGTH) {
            return ExtractionResult::failed($url, 'empty');
        }

        $leadImage = new LeadImageCandidate($article->image, $pageImages);
        $body = $this->bodyCleaner->clean(
            $article->content,
            [$article->title, $entryTitle],
            $leadImage,
            $media,
            $entryAuthor,
        );
        $clean = $this->sanitizer->sanitize($body);
        if ($clean === null) {
            return ExtractionResult::failed($url, 'empty');
        }

        return ExtractionResult::ok(
            url: $page->finalUrl,
            title: $article->title,
            byline: $article->byline,
            siteName: $article->siteName,
            contentHtml: $clean,
            excerpt: $article->excerpt,
        );
    }

    /**
     * Keep the richer of two extractions of the page: the passed score-neutral
     * document (repairs only) and the wrapper-chain-collapsed variant (#235). The
     * collapse rescues block-component pages (#235) and breaks some
     * well-structured ones (#476); the longer body is the better one in both
     * directions. collapseWrapperChains() returns null when there is no chain to
     * collapse, so the second extraction is skipped.
     *
     * The conservative document is passed in already normalised because the
     * caller reads its image inventory before readability consumes (mutates) it
     * (#684).
     */
    private function richestArticle(?HTMLDocument $normalized, PageResponse $page): ?Article
    {
        $conservative = $this->parse($normalized, $page->finalUrl);
        $collapsed = $this->parse($this->normalizer->collapseWrapperChains($page->html), $page->finalUrl);

        return $this->richer($conservative, $collapsed);
    }

    private function parse(?HTMLDocument $document, string $finalUrl): ?Article
    {
        if ($document === null) {
            return null;
        }

        $readability = new Readability(new Configuration(
            // EdgeBoilerplateTrimmer reads class/id fingerprints on this output
            // (#582); readability strips classes by default, which would make
            // that signal a permanent no-op.
            keepClasses: true,
            fixRelativeURLs: true,
            originalURL: $finalUrl,
        ));

        try {
            return $readability->parse($document);
        } catch (ParseException) {
            return null;
        }
    }

    /** Keep the extraction with more readable text; a tie keeps the conservative one. */
    private function richer(?Article $conservative, ?Article $collapsed): ?Article
    {
        if ($conservative === null) {
            return $collapsed;
        }
        if ($collapsed === null) {
            return $conservative;
        }

        return $this->textLength($collapsed) > $this->textLength($conservative)
            ? $collapsed
            : $conservative;
    }

    private function textLength(Article $article): int
    {
        return mb_strlen(trim((string) $article->textContent));
    }
}
