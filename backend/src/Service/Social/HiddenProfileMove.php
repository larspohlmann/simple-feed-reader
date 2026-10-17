<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Subscription;
use App\Enum\SocialNetwork;
use App\Repository\EntryTwinRepository;
use App\Repository\SocialAccountRepository;
use App\Service\SavedPosts\SavedStateMover;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\HiddenProfileMoveOutcome;
use App\Service\Subscription\Exception\AlreadySubscribedException;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Service\Subscription\SubscriptionService;

/**
 * Moves one subscriber of a hidden profile's refused RSS feed to an author feed of their own, over two sweeps: the
 * author feed has no entries until its first fetch, which Social may not trigger (Refresh depends on Social).
 */
final readonly class HiddenProfileMove
{
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private PrivateFeeds $privateFeeds,
        private EntryTwinRepository $entryTwins,
        private SavedStateMover $savedStateMover,
        private SubscriptionService $subscriptionService,
    ) {
    }

    /**
     * @throws SubscriptionLimitReachedException
     * @throws AlreadySubscribedException
     */
    public function move(Subscription $rssSubscription, HiddenProfileModel $profile): HiddenProfileMoveOutcome
    {
        $account = $this->socialAccounts->findReadyActingOnNetwork($rssSubscription->getUser(), SocialNetwork::Bluesky);
        if (null === $account) {
            return HiddenProfileMoveOutcome::Skipped;
        }
        $author = $profile->toAuthorFeed();
        $authorSubscription = $this->privateFeeds->subscriptionOf($account, $author);
        if (null === $authorSubscription) {
            $this->privateFeeds->subscribeInPlaceOf($account, $author, $rssSubscription);

            return HiddenProfileMoveOutcome::Started;
        }
        if (null === $authorSubscription->getFeed()->getLastSuccessfulFetchAt()) {
            return HiddenProfileMoveOutcome::Waiting;
        }
        $this->finish($rssSubscription, $authorSubscription);

        return HiddenProfileMoveOutcome::Finished;
    }

    private function finish(Subscription $rssSubscription, Subscription $authorSubscription): void
    {
        $userId = $rssSubscription->getUser()->requireId();
        $this->savedStateMover->move($userId, $this->entryTwins->sameGuidEntryIds(
            $userId,
            $rssSubscription->getFeed()->requireId(),
            $authorSubscription->getFeed()->requireId(),
        ));
        $this->subscriptionService->unsubscribe($rssSubscription);
    }
}
