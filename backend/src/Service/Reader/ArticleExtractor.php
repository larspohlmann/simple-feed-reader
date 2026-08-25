<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Exception\PageFetchException;
use App\Service\Sanitize\EntrySanitizer;
use Dom\HTMLDocument;
use fivefilters\Readability\Article;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;

/**
 * Turns an article URL into clean, sanitized, distraction-free HTML:
 * fetch (SSRF-guarded) → page normalization → readability extraction →
 * duplicate-title removal → edge-boilerplate trimming → EntrySanitizer (the
 * same XSS barrier feed HTML crosses). Never throws for an ordinary failure —
 * returns a `failed` ExtractionResult with a machine reason so the endpoint
 * stays 200 and the client can fall back to feed content.
 *
 * The lead picture is carried through undecided: whether it may lead the
 * article depends on the body the client shows, which is ReaderHeroResolver's
 * concern (#592).
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
    ) {
    }

    public function extract(string $url, ?string $entryTitle = null): ExtractionResult
    {
        try {
            $page = $this->fetcher->fetch($url);
        } catch (PageFetchException) {
            return ExtractionResult::failed($url, 'fetch');
        }

        $article = $this->richestArticle($page);
        if ($article === null) {
            return ExtractionResult::failed($url, 'unextractable');
        }

        if ($article->content === null || !$article->hasContent()) {
            return ExtractionResult::failed($url, 'empty');
        }
        if (mb_strlen(trim((string) $article->textContent)) < self::MIN_CONTENT_LENGTH) {
            return ExtractionResult::failed($url, 'empty');
        }

        $body = $this->bodyCleaner->clean($article->content, [$article->title, $entryTitle]);
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
            imageCandidate: $article->image,
        );
    }

    /**
     * Extract the page twice — with the score-neutral repairs only, and with the
     * wrapper-chain collapse (#235) as well — and keep the richer result. The
     * collapse rescues block-component pages (#235) and breaks some
     * well-structured ones (#476); the longer body is the better one in both
     * directions. collapseWrapperChains() returns null when there is no chain to
     * collapse, so the second extraction is skipped.
     */
    private function richestArticle(PageResponse $page): ?Article
    {
        $conservative = $this->parse($this->normalizer->normalize($page->html), $page->finalUrl);
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
