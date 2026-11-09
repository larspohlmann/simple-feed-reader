<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Enum\FeedGroup;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\NetworkScope;
use App\Service\Refresh\Model\RefreshTarget\EveryFeedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\FeedGroupTargetModel;
use App\Service\Refresh\Model\RefreshTarget\FeedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\NetworkTargetModel;
use App\Service\Refresh\Model\RefreshTarget\NeverFetchedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\TagTargetModel;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Refresh\Model\RefreshRequestModel;

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

    public function requestFor(int $userId, ?int $feedId, ?int $tagId): RefreshRequestModel
    {
        if (null !== $feedId) {
            return $this->forFeed($userId, $feedId);
        }

        if (null !== $tagId) {
            $tag = $this->tags->getOneForUser($userId, $tagId);

            return RefreshRequestModel::forUser($userId, new TagTargetModel($tag->requireId()), self::BUDGET_SECONDS);
        }

        return RefreshRequestModel::forUser($userId, new EveryFeedTargetModel(), self::BUDGET_SECONDS);
    }

    public function requestForNetwork(int $userId, NetworkScope $networkScope): RefreshRequestModel
    {
        return RefreshRequestModel::forUser($userId, new NetworkTargetModel($networkScope), self::BUDGET_SECONDS);
    }

    public function requestForFeedGroup(int $userId, FeedGroup $feedGroup): RefreshRequestModel
    {
        return RefreshRequestModel::forUser($userId, new FeedGroupTargetModel($feedGroup), self::BUDGET_SECONDS);
    }

    public function requestForNeverFetched(int $userId): RefreshRequestModel
    {
        return RefreshRequestModel::forUser($userId, new NeverFetchedTargetModel(), self::BUDGET_SECONDS);
    }

    private function forFeed(int $userId, int $feedId): RefreshRequestModel
    {
        if (!$this->subscriptions->existsForUserAndFeed($userId, $feedId)) {
            throw new RecordNotFoundException('No such subscription.');
        }

        return RefreshRequestModel::forUser($userId, new FeedTargetModel($feedId), self::BUDGET_SECONDS);
    }
}
