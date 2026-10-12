<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;

/** One refresh pass's private feeds, loaded with their accounts at once, and the accounts throttled so far. */
final class SocialRefreshPass
{
    /** @var list<int> */
    private array $throttledAccountIds = [];

    /** @param array<int, SocialFeed> $socialFeedsByFeedId */
    public function __construct(private readonly array $socialFeedsByFeedId)
    {
    }

    public function socialFeedOf(Feed $feed): ?SocialFeed
    {
        return $this->socialFeedsByFeedId[$feed->requireId()] ?? null;
    }

    public function throttle(SocialAccount $account): void
    {
        $this->throttledAccountIds[] = $account->requireId();
    }

    public function isThrottled(SocialAccount $account): bool
    {
        return \in_array($account->requireId(), $this->throttledAccountIds, true);
    }
}
