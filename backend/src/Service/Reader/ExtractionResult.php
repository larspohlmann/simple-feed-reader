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
    /** True for a successful extraction. Derived, not stored: a failure always
     *  carries a reason and a success never does, so the two cannot disagree. */
    public bool $ok;

    private function __construct(
        public ?string $url,
        public ?string $reason,
        public ?string $detail,
        public ?string $title,
        public ?string $byline,
        public ?string $siteName,
        public ?string $contentHtml,
        public ?string $excerpt,
        public bool $paywalled,
    ) {
        $this->ok = $reason === null;
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
        return new self($url, null, null, $title, $byline, $siteName, $contentHtml, $excerpt, $paywalled);
    }

    /** `$detail` is the underlying cause in words when one exists — a fetch carries
     *  the HTTP status or transport message; a reason with no such cause passes null. */
    public static function failed(?string $url, string $reason, ?string $detail = null): self
    {
        return new self($url, $reason, $detail, null, null, null, null, null, false);
    }
}
