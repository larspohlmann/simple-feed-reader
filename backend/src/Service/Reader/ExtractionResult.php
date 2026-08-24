<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * Discriminated outcome of an extraction. `ok` carries the cleaned article;
 * `failed` carries a machine reason the client switches on:
 *   no_url        — the entry has no source URL to fetch
 *   fetch         — the page could not be retrieved (network / SSRF-blocked / oversized)
 *   unextractable — readability could not find an article
 *   empty         — extraction produced nothing after sanitization
 */
final readonly class ExtractionResult
{
    private function __construct(
        public bool $ok,
        public ?string $url,
        public ?string $reason,
        public ?string $title,
        public ?string $byline,
        public ?string $siteName,
        public ?string $contentHtml,
        public ?string $excerpt,
        // The page's own picture — readability finds the og:image even when it
        // sits outside the extracted content. An undecided candidate: whether it
        // may lead the article is ReaderHeroResolver's call, not the extractor's.
        public ?string $imageCandidate,
    ) {
    }

    public static function ok(
        string $url,
        string $title,
        ?string $byline,
        ?string $siteName,
        string $contentHtml,
        ?string $excerpt,
        ?string $imageCandidate,
    ): self {
        return new self(true, $url, null, $title, $byline, $siteName, $contentHtml, $excerpt, $imageCandidate);
    }

    public static function failed(?string $url, string $reason): self
    {
        return new self(false, $url, $reason, null, null, null, null, null, null);
    }
}
