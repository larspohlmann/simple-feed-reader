<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Repository\SubscriptionDisplayTitle;
use App\Repository\SubscriptionRepository;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\ImportCandidatesModel;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\ProfileFeedSubscriptionModel;
use App\Service\Social\Pass\FollowMatching;
use App\Service\Social\SocialCandidates\SocialCandidatesInterface;
use App\Service\Social\Support\CandidateOrder;
use App\Service\Social\Support\ProfileFeedUrl;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ImportCandidates
{
    public function __construct(
        private SocialCandidatesInterface $networkCandidates,
        private SubscriptionRepository $subscriptions,
        private FollowMatcher $followMatcher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws SocialCredentialsRejectedException once the account is marked as needing a reconnect
     * @throws SocialNetworkUnreachableException
     */
    public function forAccount(SocialAccount $account): ImportCandidatesModel
    {
        $network = $this->networkCandidatesOf($account);
        $follows = $this->followMatcher->match(
            new FollowMatching($account->getNetwork(), $network->follows),
            $this->profileFeedsOf($account),
        );

        return new ImportCandidatesModel(
            CandidateOrder::follows($follows),
            CandidateOrder::collections($network->lists),
            CandidateOrder::collections($network->customFeeds),
            $network->truncated,
        );
    }

    private function networkCandidatesOf(SocialAccount $account): NetworkCandidatesModel
    {
        try {
            return $this->networkCandidates->candidates($account);
        } catch (SocialCredentialsRejectedException $exception) {
            $account->markNeedsReconnect();
            $this->entityManager->flush();

            throw $exception;
        }
    }

    /** @return list<ProfileFeedSubscriptionModel> */
    private function profileFeedsOf(SocialAccount $account): array
    {
        $profileFeeds = [];
        foreach ($this->subscriptions->findForUserWithTags($account->getUser()->requireId()) as $subscription) {
            $feed = $subscription->getFeed();
            $identity = ProfileFeedUrl::identityOf($account->getNetwork(), $feed->getUrl());
            if (null === $identity) {
                continue;
            }
            $profileFeeds[] = new ProfileFeedSubscriptionModel(
                $subscription->requireId(),
                $identity,
                $feed->getUrl(),
                SubscriptionDisplayTitle::from($subscription->getCustomTitle(), $feed->getTitle(), $feed->getUrl()),
            );
        }

        return $profileFeeds;
    }
}
