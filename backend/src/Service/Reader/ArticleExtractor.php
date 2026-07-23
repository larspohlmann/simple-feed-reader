<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\EntrySanitizer;
use App\Service\Reader\Exception\PageFetchException;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;

/**
 * Turns an article URL into clean, sanitized, distraction-free HTML:
 * fetch (SSRF-guarded) → readability extraction → EntrySanitizer (the same XSS
 * barrier feed HTML crosses). Never throws for an ordinary failure — returns a
 * `failed` ExtractionResult with a machine reason so the endpoint stays 200 and
 * the client can fall back to feed content.
 */
final class ArticleExtractor implements ArticleExtractorInterface
{
    /** Below this many characters of extracted text, treat as not an article. */
    private const int MIN_CONTENT_LENGTH = 200;

    public function __construct(
        private readonly HtmlPageFetcher $fetcher,
        private readonly EntrySanitizer $sanitizer,
    ) {
    }

    public function extract(string $url): ExtractionResult
    {
        try {
            $page = $this->fetcher->fetch($url);
        } catch (PageFetchException) {
            return ExtractionResult::failed($url, 'fetch');
        }

        $readability = new Readability(new Configuration(
            fixRelativeURLs: true,
            originalURL: $page->finalUrl,
        ));

        try {
            $article = $readability->parse($page->html);
        } catch (ParseException) {
            return ExtractionResult::failed($url, 'unextractable');
        }

        if (!$article->hasContent() || $article->content === null) {
            return ExtractionResult::failed($url, 'empty');
        }
        if (mb_strlen(trim((string) $article->textContent)) < self::MIN_CONTENT_LENGTH) {
            return ExtractionResult::failed($url, 'empty');
        }

        $clean = $this->sanitizer->sanitize($article->content);
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
