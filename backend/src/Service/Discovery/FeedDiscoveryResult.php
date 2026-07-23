<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/**
 * @phpstan-type ScrapeFailureReason 'blocked'|'unreachable'|'not_scrapable'
 */
final readonly class FeedDiscoveryResult
{
    /**
     * @param list<FeedCandidate> $candidates
     * @param ScrapeFailureReason|null $scrapeFailureReason
     */
    private function __construct(
        public bool $isDirectFeed,
        public ?string $feedUrl,
        public array $candidates,
        public ?string $scrapeFailureReason = null,
    ) {
    }

    public static function directFeed(string $feedUrl): self
    {
        return new self(true, $feedUrl, []);
    }

    /** @param list<FeedCandidate> $candidates */
    public static function candidates(array $candidates): self
    {
        return new self(false, null, $candidates);
    }

    /**
     * Discovery found nothing to offer — not even a scraped fallback — and the
     * reason says why, so the caller can tell "the site refused us" (blocked:
     * 401/403/429), "we never got an answer" (unreachable) and "we got a page
     * but no article list" (not_scrapable) apart. A result, not an exception:
     * every one of these is an expected outcome the subscribe UI must render,
     * not an error condition.
     *
     * @param ScrapeFailureReason $reason
     */
    public static function scrapeFailed(string $reason): self
    {
        return new self(false, null, [], $reason);
    }
}
