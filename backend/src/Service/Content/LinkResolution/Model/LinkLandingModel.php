<?php

declare(strict_types=1);

namespace App\Service\Content\LinkResolution\Model;

use App\Enum\LinkResolution;

/** Where one attempt left a short link: a landing elsewhere, the posted URL itself, no answer, or one worth retrying. */
final readonly class LinkLandingModel
{
    private function __construct(
        public LinkResolution $resolution,
        public ?string $url,
        public ?string $urlHash,
    ) {
    }

    public static function resolved(string $url, string $urlHash): self
    {
        return new self(LinkResolution::Resolved, $url, $urlHash);
    }

    public static function unchanged(): self
    {
        return new self(LinkResolution::Unchanged, null, null);
    }

    public static function unresolved(): self
    {
        return new self(LinkResolution::Unresolved, null, null);
    }

    /** A timeout, a lost connection, or a shortener answering 429 or 5xx itself: the row stays pending. */
    public static function retryable(): self
    {
        return new self(LinkResolution::Pending, null, null);
    }

    public function isRetryable(): bool
    {
        return $this->resolution === LinkResolution::Pending;
    }
}
