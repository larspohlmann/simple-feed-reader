<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Repository\SubscriptionDisplayTitle;
use App\Repository\SubscriptionFeedUrls;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\ImportCandidateModel;
use App\Service\Social\Model\ImportCandidateState;
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
        private SubscriptionFeedUrls $subscriptionFeedUrls,
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
            CandidateOrder::follows(self::trustedFollows($network, $follows)),
            CandidateOrder::collections($network->lists),
            CandidateOrder::collections($network->customFeeds),
            $network->truncated,
        );
    }

    /**
     * @param list<ImportCandidateModel> $follows
     *
     * @return list<ImportCandidateModel> without unfollows when the follow list was cut off
     */
    private static function trustedFollows(NetworkCandidatesModel $network, array $follows): array
    {
        if (!$network->truncated) {
            return $follows;
        }

        return array_values(array_filter(
            $follows,
            static fn (ImportCandidateModel $follow): bool => ImportCandidateState::NoLongerFollowed !== $follow->state,
        ));
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
        foreach ($this->subscriptionFeedUrls->forUser($account->getUser()->requireId()) as $subscription) {
            $identity = ProfileFeedUrl::identityOf($account->getNetwork(), $subscription['feedUrl']);
            if (null === $identity) {
                continue;
            }
            $profileFeeds[] = new ProfileFeedSubscriptionModel(
                $subscription['id'],
                $identity,
                $subscription['feedUrl'],
                SubscriptionDisplayTitle::from(
                    $subscription['customTitle'],
                    $subscription['feedTitle'],
                    $subscription['feedUrl'],
                ),
            );
        }

        return $profileFeeds;
    }
}
