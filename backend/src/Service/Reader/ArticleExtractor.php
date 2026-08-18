<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\EntrySanitizer;
use App\Service\Reader\Exception\PageFetchException;
use fivefilters\Readability\Article;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;

/**
 * Turns an article URL into clean, sanitized, distraction-free HTML:
 * fetch (SSRF-guarded) → page normalization → readability extraction →
 * duplicate-title removal → EntrySanitizer (the same XSS barrier feed HTML
 * crosses). Never throws for an ordinary failure — returns a
 * `failed` ExtractionResult with a machine reason so the endpoint stays 200 and
 * the client can fall back to feed content.
 */
final class ArticleExtractor implements ArticleExtractorInterface
{
    /** Below this many characters of extracted text, treat as not an article. */
    private const int MIN_CONTENT_LENGTH = 200;

    public function __construct(
        private readonly HtmlPageFetcher $fetcher,
        private readonly FetchedPageNormalizer $normalizer,
        private readonly LeadingTitleRemover $titleRemover,
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

        $withoutTitle = $this->titleRemover->remove($article->content, [$article->title, $entryTitle]);
        $clean = $this->sanitizer->sanitize($withoutTitle);
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
            image: $this->leadImage($article->image, $clean),
        );
    }

    /**
     * Extract the page twice — with the score-neutral repairs only, and with the
     * wrapper-chain collapse (#235) as well — and keep the richer result. The
     * collapse rescues block-component pages (#235) and breaks some
     * well-structured ones (#476); the longer body is the better one in both
     * directions. The second parse is skipped when the collapse changed nothing.
     */
    private function richestArticle(PageResponse $page): ?Article
    {
        $conservative = $this->normalizer->normalize($page->html);
        $collapsed = $this->normalizer->collapseWrapperChains($conservative);

        $fromConservative = $this->parse($conservative, $page->finalUrl);
        $fromCollapsed = $collapsed === $conservative
            ? null
            : $this->parse($collapsed, $page->finalUrl);

        return $this->richer($fromConservative, $fromCollapsed);
    }

    private function parse(string $html, string $finalUrl): ?Article
    {
        $readability = new Readability(new Configuration(
            fixRelativeURLs: true,
            originalURL: $finalUrl,
        ));

        try {
            return $readability->parse($html);
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

    /**
     * The article's main image, to render as a hero — but only when the extracted
     * body has none of its own (readability often drops a hero that sits outside
     * the scored content). Guarded to http(s) so a javascript:/data: URL from the
     * page can never reach the client's <img src>.
     */
    private function leadImage(?string $image, string $content): ?string
    {
        if ($image === null || preg_match('#^https?://#i', $image) !== 1) {
            return null;
        }

        return str_contains($content, '<img') ? null : $image;
    }
}
