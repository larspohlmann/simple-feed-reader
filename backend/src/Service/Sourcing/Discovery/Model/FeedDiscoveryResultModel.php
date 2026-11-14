<?php

declare(strict_types=1);

namespace App\Service\Discovery\Model;

final readonly class FeedDiscoveryResultModel
{
    /**
     * @param DiscoveredFeedModel|null $feed the feed with its document, which the subscribe stores
     *                                       instead of refetching
     * @param list<FeedCandidateModel> $candidates
     */
    private function __construct(
        public ?DiscoveredFeedModel $feed,
        public array $candidates,
        public ?ScrapeFailureReason $scrapeFailureReason = null,
    ) {
    }

    public static function directFeed(DiscoveredFeedModel $feed): self
    {
        return new self($feed, []);
    }

    /** @param list<FeedCandidateModel> $candidates */
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
