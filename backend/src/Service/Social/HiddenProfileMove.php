<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
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
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moves one subscriber of a hidden profile's refused RSS feed to an author feed of their own, over two sweeps: the
 * author feed has no entries until its first fetch, which Social may not trigger (Refresh depends on Social).
 */
final readonly class HiddenProfileMove
{
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private AuthorFeeds $authorFeeds,
        private EntryTwinRepository $entryTwins,
        private SavedStateMover $savedStateMover,
        private SubscriptionService $subscriptionService,
        private EntityManagerInterface $entityManager,
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
        $authorSubscription = $this->authorFeeds->subscriptionOf($account->getUser(), $profile->did);
        if (null === $authorSubscription) {
            $this->start($account, $profile, $rssSubscription);

            return HiddenProfileMoveOutcome::Started;
        }
        if (null === $authorSubscription->getFeed()->getLastSuccessfulFetchAt()) {
            return HiddenProfileMoveOutcome::Waiting;
        }
        $this->finish($rssSubscription, $authorSubscription);

        return HiddenProfileMoveOutcome::Finished;
    }

    private function start(SocialAccount $account, HiddenProfileModel $profile, Subscription $rssSubscription): void
    {
        $this->authorFeeds->create($account, $profile, [])->takeSettingsFrom($rssSubscription);
        $this->entityManager->flush();
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
