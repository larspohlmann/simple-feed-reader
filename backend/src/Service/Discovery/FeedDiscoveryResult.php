<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/**
 * @phpstan-type ScrapeFailureReason 'blocked'|'throttled'|'unreachable'|'not_scrapable'
 */
final readonly class FeedDiscoveryResult
{
    /**
     * @param DiscoveredFeed|null      $feed the feed itself, address and
     *   document together, when discovery got that far — the document is what
     *   the subscribe stores instead of fetching the address again
     * @param list<FeedCandidate>      $candidates
     * @param ScrapeFailureReason|null $scrapeFailureReason
     */
    private function __construct(
        public ?DiscoveredFeed $feed,
        public array $candidates,
        public ?string $scrapeFailureReason = null,
    ) {
    }

    public static function directFeed(DiscoveredFeed $feed): self
    {
        return new self($feed, []);
    }

    /** @param list<FeedCandidate> $candidates */
    public static function candidates(array $candidates): self
    {
        return new self(null, $candidates);
    }

    /**
     * Discovery found nothing to offer — not even a scraped fallback — and the
     * reason says why, so the caller can tell "the site refused us" (blocked:
     * 401/403), "the site is rationing requests" (throttled: 429), "we never
     * got an answer" (unreachable) and "we got a page but no article list"
     * (not_scrapable) apart. A result, not an exception:
     * every one of these is an expected outcome the subscribe UI must render,
     * not an error condition.
     *
     * @param ScrapeFailureReason $reason
     *
     * @return FeedDiscoveryResult
     */
    public static function scrapeFailed(string $reason): self
    {
        return new self(null, [], $reason);
    }
}
