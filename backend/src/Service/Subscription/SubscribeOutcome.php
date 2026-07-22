<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Service\Discovery\FeedCandidate;

final readonly class SubscribeOutcome
{
    /** @param list<FeedCandidate> $candidates */
    private function __construct(
        public ?Subscription $subscription,
        public array $candidates,
    ) {
    }

    public static function subscribed(Subscription $subscription): self
    {
        return new self($subscription, []);
    }

    /** @param list<FeedCandidate> $candidates */
    public static function candidates(array $candidates): self
    {
        return new self(null, $candidates);
    }
}
