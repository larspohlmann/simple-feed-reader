<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Subscription;
use App\Enum\SocialNetwork;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Refresh\Model\RefreshFeedSetModel;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Social\NetworkSubscriptions;

/** A foreign or unknown feed or tag is a 404, not a 403, so the refresh endpoint never confirms that it exists. */
final readonly class UserRefreshScope
{
    /** Above BudgetedFeedQueue::SAFETY_MARGIN_SECONDS (10), so a call covers several feeds; below FastCGI limits. */
    private const int BUDGET_SECONDS = 25;

    public function __construct(
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
        private NetworkSubscriptions $networkSubscriptions,
    ) {
    }

    public function requestFor(int $userId, ?int $feedId, ?int $tagId): RefreshRequestModel
    {
        if (null !== $feedId) {
            return $this->forFeed($userId, $feedId);
        }

        if (null !== $tagId) {
            $tag = $this->tags->getOneForUser($userId, $tagId);

            return RefreshRequestModel::forUserTag($userId, $tag->requireId(), self::BUDGET_SECONDS);
        }

        return RefreshRequestModel::forUser($userId, self::BUDGET_SECONDS);
    }

    public function requestForNetwork(int $userId, SocialNetwork $network): RefreshRequestModel
    {
        $feedIds = array_map(
            static fn (Subscription $subscription): int => $subscription->getFeed()->requireId(),
            $this->networkSubscriptions->of($userId, $network),
        );

        return RefreshRequestModel::forUserFeedSet(
            $userId,
            new RefreshFeedSetModel('network-' . $network->value, $feedIds),
            self::BUDGET_SECONDS,
        );
    }

    private function forFeed(int $userId, int $feedId): RefreshRequestModel
    {
        if (!$this->subscriptions->existsForUserAndFeed($userId, $feedId)) {
            throw new RecordNotFoundException('No such subscription.');
        }

        return RefreshRequestModel::forUserFeed($userId, $feedId, self::BUDGET_SECONDS);
    }
}
