<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Repository\Exception\RecordNotFoundException;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;

/** A foreign or unknown feed or tag is a 404, not a 403, so the refresh endpoint never confirms that it exists. */
final readonly class UserRefreshScope
{
    /** Above BudgetedFeedQueue::SAFETY_MARGIN_SECONDS (10), so a call covers several feeds; below FastCGI limits. */
    private const int BUDGET_SECONDS = 25;

    public function __construct(
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
    ) {
    }

    public function requestFor(int $userId, ?int $feedId, ?int $tagId): RefreshRequest
    {
        if (null !== $feedId) {
            return $this->forFeed($userId, $feedId);
        }

        if (null !== $tagId) {
            $tag = $this->tags->getOneForUser($userId, $tagId);

            return RefreshRequest::forUserTag($userId, $tag->requireId(), self::BUDGET_SECONDS);
        }

        return RefreshRequest::forUser($userId, self::BUDGET_SECONDS);
    }

    private function forFeed(int $userId, int $feedId): RefreshRequest
    {
        if (!$this->subscriptions->existsForUserAndFeed($userId, $feedId)) {
            throw new RecordNotFoundException('No such subscription.');
        }

        return RefreshRequest::forUserFeed($userId, $feedId, self::BUDGET_SECONDS);
    }
}
