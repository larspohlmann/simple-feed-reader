<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Service\Discovery\FeedCandidate;
use App\Service\Discovery\FeedDiscoveryResult;

/**
 * @phpstan-import-type ScrapeFailureReason from FeedDiscoveryResult
 */
final readonly class SubscribeOutcome
{
    /**
     * @param list<FeedCandidate> $candidates
     * @param ScrapeFailureReason|null $scrapeFailureReason
     */
    private function __construct(
        public ?Subscription $subscription,
        public array $candidates,
        public ?string $scrapeFailureReason = null,
    ) {
    }

    public static function subscribed(Subscription $subscription): self
    {
        return new self($subscription, []);
    }

    /**
     * An empty candidate list is a legitimate outcome — the reason then tells
     * the subscribe dialog WHY there is nothing to offer (site blocked us,
     * never answered, or had no extractable article list).
     *
     * @param list<FeedCandidate> $candidates
     * @param ScrapeFailureReason|null $scrapeFailureReason
     */
    public static function candidates(array $candidates, ?string $scrapeFailureReason = null): self
    {
        return new self(null, $candidates, $scrapeFailureReason);
    }
}
