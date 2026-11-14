<?php

declare(strict_types=1);

namespace App\Service\Reader\Model;

/**
 * An extraction's outcome: the cleaned article, or why it failed, so the client falls back to the feed body.
 * `paywalled` marks an ok body that is the free preview of a paywalled article.
 */
final readonly class ExtractionResultModel
{
    /** Derived, not stored: a failure always carries a reason and a success never does. */
    public bool $ok;

    private function __construct(
        public ?string $url,
        public ?ExtractionFailure $reason,
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

    /** `$detail` is the underlying cause in words when there is one, such as a fetch's HTTP status. */
    public static function failed(?string $url, ExtractionFailure $reason, ?string $detail = null): self
    {
        return new self($url, $reason, $detail, null, null, null, null, null, false);
    }
}
