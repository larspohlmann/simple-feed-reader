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
 *   mismatch      — the extraction did not reflect the article the feed carries (#654)
 *
 * `paywalled` marks an ok body that is the free preview of a paywalled article (#785).
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
        public bool $paywalled,
    ) {
    }

    public static function ok(
        string $url,
        string $title,
        ?string $byline,
        ?string $siteName,
        string $contentHtml,
        ?string $excerpt,
        bool $paywalled = false,
    ): self {
        return new self(true, $url, null, $title, $byline, $siteName, $contentHtml, $excerpt, $paywalled);
    }

    public static function failed(?string $url, string $reason): self
    {
        return new self(false, $url, $reason, null, null, null, null, null, false);
    }
}
