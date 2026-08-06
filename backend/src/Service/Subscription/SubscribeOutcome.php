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
     * @param Subscription|null   $subscription
     * @param list<FeedCandidate> $candidates
     * @param string|null         $scrapeFailureReason
     * @param int                 $unreadCount entries the subscribe itself
     *   stored — nobody has read a feed they have just added, so this is its
     *   unread count, and counting it again in the database would mean
     *   aggregating every entry of every feed this user has
     */
    private function __construct(
        public ?Subscription $subscription,
        public array $candidates,
        public ?string $scrapeFailureReason = null,
        public int $unreadCount = 0,
    ) {
    }

    public static function subscribed(Subscription $subscription, int $unreadCount = 0): self
    {
        return new self($subscription, [], null, $unreadCount);
    }

    /**
     * An empty candidate list is a legitimate outcome — the reason then tells
     * the subscribe dialog WHY there is nothing to offer (site blocked us,
     * never answered, or had no extractable article list).
     *
     * @param list<FeedCandidate> $candidates
     * @param string|null         $scrapeFailureReason
     *
     * @return SubscribeOutcome
     */
    public static function candidates(array $candidates, ?string $scrapeFailureReason = null): self
    {
        return new self(null, $candidates, $scrapeFailureReason);
    }
}
