<?php

declare(strict_types=1);

namespace App\Service\Discovery;

final readonly class FeedDiscoveryResult
{
    /**
     * @param DiscoveredFeed|null $feed the feed with its document, which the subscribe stores instead of refetching
     * @param list<FeedCandidate> $candidates
     */
    private function __construct(
        public ?DiscoveredFeed $feed,
        public array $candidates,
        public ?ScrapeFailureReason $scrapeFailureReason = null,
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

    /** Nothing to offer, not even a scraped fallback: an outcome the subscribe UI renders, not an error. */
    public static function scrapeFailed(ScrapeFailureReason $reason): self
    {
        return new self(null, [], $reason);
    }
}
