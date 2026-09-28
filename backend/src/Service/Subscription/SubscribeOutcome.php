<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Discovery\Model\ScrapeFailureReason;

final readonly class SubscribeOutcome
{
    /**
     * @param list<FeedCandidateModel> $candidates
     * @param int                 $unreadCount the entries the subscribe stored; nobody has read a feed just added
     */
    private function __construct(
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public ?Subscription $subscription,
        public array $candidates,
        public ?ScrapeFailureReason $scrapeFailureReason = null,
        public int $unreadCount = 0,
    ) {
    }

    public static function subscribed(Subscription $subscription, int $unreadCount = 0): self
    {
        return new self($subscription, [], null, $unreadCount);
    }

    /** @param list<FeedCandidateModel> $candidates an empty list is a legitimate outcome; the reason says why */
    public static function candidates(array $candidates, ?ScrapeFailureReason $scrapeFailureReason = null): self
    {
        return new self(null, $candidates, $scrapeFailureReason);
    }
}
