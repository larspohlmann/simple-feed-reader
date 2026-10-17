<?php

declare(strict_types=1);

namespace App\Service\SavedPosts\Factory;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Feed\Factory\FeedFactory;
use App\Service\SavedPosts\Support\SavedFeedUrl;

final readonly class SavedFeedFactory
{
    public function __construct(
        private FeedFactory $feedFactory,
        private NaiveUtcClock $clock,
    ) {
    }

    public function feed(int $userId, ?SocialNetwork $network): Feed
    {
        return $this->feedFactory->create(
            SavedFeedUrl::of($userId, $network),
            SourceFormat::SAVED,
            'Saved from ' . ($network?->displayName() ?? 'feeds'),
        );
    }

    public function subscription(User $user, Feed $feed, int $position): Subscription
    {
        $subscription = new Subscription($user, $feed, $this->clock->now());
        $subscription->setPosition($position);
        $subscription->setIncludeInAllItems(false);
        $subscription->setIncludeInForYou(false);

        return $subscription;
    }
}
