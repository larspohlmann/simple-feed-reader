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
final class ArticleExtractor
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

        $config = new Configuration();
        $config->setFixRelativeURLs(true);
        $config->setOriginalURL($page->finalUrl);

        $readability = new Readability($config);
        try {
            $readability->parse($page->html);
        } catch (ParseException) {
            return ExtractionResult::failed($url, 'unextractable');
        }

        $rawContent = $readability->getContent();
        if ($rawContent === null || mb_strlen(strip_tags($rawContent)) < self::MIN_CONTENT_LENGTH) {
            return ExtractionResult::failed($url, 'empty');
        }

        $clean = $this->sanitizer->sanitize($rawContent);
        if ($clean === null) {
            return ExtractionResult::failed($url, 'empty');
        }

        return ExtractionResult::ok(
            url: $page->finalUrl,
            title: $readability->getTitle() ?? '',
            byline: $readability->getAuthor(),
            siteName: $readability->getSiteName(),
            contentHtml: $clean,
            excerpt: $readability->getExcerpt(),
        );
    }
}
