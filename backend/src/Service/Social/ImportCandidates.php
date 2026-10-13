<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Enum\SocialFeedKind;
use App\Repository\SocialFeedRepository;
use App\Repository\SubscriptionDisplayTitle;
use App\Repository\SubscriptionFeedUrls;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\CollectionCandidateModel;
use App\Service\Social\Model\ImportCandidateModel;
use App\Service\Social\Model\ImportCandidateState;
use App\Service\Social\Model\ImportCandidatesModel;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\ProfileFeedSubscriptionModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\Pass\FollowMatching;
use App\Service\Social\SocialCandidates\SocialCandidatesInterface;
use App\Service\Social\Support\CandidateOrder;
use App\Service\Social\Support\ProfileFeedUrl;

final readonly class ImportCandidates
{
    public function __construct(
        private SocialCandidatesInterface $networkCandidates,
        private SubscriptionFeedUrls $subscriptionFeedUrls,
        private FollowMatcher $followMatcher,
        private SocialFeedRepository $socialFeeds,
        private ActingAccountGuard $guard,
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
        $subscribed = $this->privateFeedSubscriptionsOf($account);

        return new ImportCandidatesModel(
            CandidateOrder::follows(self::trustedFollows($network, $follows)),
            [
                CollectionCandidateModel::of(PrivateFeedModel::home(), $subscribed),
                ...self::collections(SocialFeedKind::UserList, $network->lists, $subscribed),
            ],
            self::collections(SocialFeedKind::CustomFeed, $network->customFeeds, $subscribed),
            $network->truncated,
        );
    }

    /**
     * @param list<SocialCollectionModel> $collections
     * @param array<string, int>          $subscribed subscription id by PrivateFeedModel::key()
     *
     * @return list<CollectionCandidateModel> by name
     */
    private static function collections(SocialFeedKind $kind, array $collections, array $subscribed): array
    {
        return array_map(
            static fn (SocialCollectionModel $collection): CollectionCandidateModel => CollectionCandidateModel::of(
                PrivateFeedModel::of($kind, $collection->id, $collection->name),
                $subscribed,
            ),
            CandidateOrder::collections($collections),
        );
    }

    /** @return array<string, int> subscription id by PrivateFeedModel::key() */
    private function privateFeedSubscriptionsOf(SocialAccount $account): array
    {
        $subscribed = [];
        foreach ($this->socialFeeds->subscribedCollectionsOf($account) as $collection) {
            $key = PrivateFeedModel::keyOf($collection['kind'], $collection['remoteId']);
            $subscribed[$key] = $collection['subscriptionId'];
        }

        return $subscribed;
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
        return $this->guard->actAs(
            $account,
            fn (): NetworkCandidatesModel => $this->networkCandidates->candidates($account),
        );
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
