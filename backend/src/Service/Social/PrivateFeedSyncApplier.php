<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\PrivateFeedSyncChange;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Enum\PrivateFeedSyncReason;
use App\Repository\KnownSocialCollectionRepository;
use App\Repository\PrivateFeedSyncChangeRepository;
use App\Repository\SocialFeedRepository;
use App\Repository\SubscriptionDisplayTitle;
use App\Repository\SubscriptionRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\Model\NetworkCollectionsModel;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\PrivateFeedOutcome;
use App\Service\Social\Model\PrivateFeedSyncOutcomeModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\Pass\CollectionSyncPlan;
use App\Service\Social\Support\PrivateFeedSyncReasons;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;

/** Writes one complete check: removals through the unsubscribe's rescue, additions with import defaults, the log. */
final readonly class PrivateFeedSyncApplier
{
    public const int KEPT_CHANGES = 10;

    public function __construct(
        private SocialFeedRepository $socialFeeds,
        private KnownSocialCollectionRepository $knownCollections,
        private PrivateFeedSyncChangeRepository $changes,
        private PrivateFeeds $privateFeeds,
        private SubscriptionService $subscriptionService,
        private SubscriptionRepository $subscriptions,
        private NaiveUtcClock $clock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function apply(SocialAccount $account, NetworkCollectionsModel $network): PrivateFeedSyncOutcomeModel
    {
        $plan = $this->planFor($account, $network);
        $this->entityManager->beginTransaction();
        try {
            $removed = $this->remove($account, $network, $plan->removals());
            $added = $this->add($account, $plan->additions());
            $outcome = new PrivateFeedSyncOutcomeModel($added, $removed);
            $this->rememberAndStamp($account, $plan);
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        return $outcome;
    }

    private function planFor(SocialAccount $account, NetworkCollectionsModel $network): CollectionSyncPlan
    {
        $onNetwork = self::byKey($network);
        $subscribed = $this->subscribedOf($account);
        $known = $this->knownCollections->keysOf($account);

        return null === $account->getLastSyncedAt()
            ? CollectionSyncPlan::baseline($onNetwork, $subscribed, $known)
            : CollectionSyncPlan::following($onNetwork, $subscribed, $known);
    }

    /** @param array<string, int> $removals subscription id by key */
    private function remove(SocialAccount $account, NetworkCollectionsModel $network, array $removals): int
    {
        $leaving = $this->subscriptions->findAllByIdsForUser(
            $account->getUser()->requireId(),
            array_values($removals),
        );
        $titles = self::titlesOf($leaving);
        $this->subscriptionService->unsubscribeAll($leaving);
        $keyById = array_flip($removals);
        foreach ($titles as $subscriptionId => $title) {
            $collection = PrivateFeedModel::fromKey($keyById[$subscriptionId]);
            $this->knownCollections->forget($account, $collection->kind, $collection->remoteId);
            $this->log($account, $collection, $title, PrivateFeedSyncReasons::removed($account, $collection, $network));
        }
        $this->entityManager->flush();

        return \count($leaving);
    }

    /** @param list<PrivateFeedModel> $additions */
    private function add(SocialAccount $account, array $additions): int
    {
        $added = 0;
        foreach ($additions as $collection) {
            if (!$this->created($account, $collection)) {
                continue;
            }
            $this->log($account, $collection, $collection->name, PrivateFeedSyncReasons::added($account, $collection));
            ++$added;
        }

        return $added;
    }

    private function created(SocialAccount $account, PrivateFeedModel $collection): bool
    {
        try {
            return PrivateFeedOutcome::Created === $this->privateFeeds->create($account, $collection);
        } catch (SubscriptionLimitReachedException) {
            return false;
        }
    }

    private function rememberAndStamp(SocialAccount $account, CollectionSyncPlan $plan): void
    {
        foreach ($plan->toRemember() as $collection) {
            $this->knownCollections->remember($account, $collection->kind, $collection->remoteId);
        }
        $account->recordSync($this->clock->now());
        $this->entityManager->flush();
        $this->changes->trimTo($account, self::KEPT_CHANGES);
    }

    private function log(
        SocialAccount $account,
        PrivateFeedModel $collection,
        string $name,
        PrivateFeedSyncReason $reason,
    ): void {
        $this->entityManager->persist(new PrivateFeedSyncChange(
            $account,
            $collection->kind,
            $collection->remoteId,
            $name,
            $reason,
            $this->clock->now(),
        ));
    }

    /** @return array<string, int> subscription id by key, of the allow-listed kinds only */
    private function subscribedOf(SocialAccount $account): array
    {
        $subscribed = [];
        foreach ($this->socialFeeds->subscribedCollectionsOfKinds($account, PrivateFeedSync::SYNCED_KINDS) as $row) {
            $subscribed[PrivateFeedModel::keyOf($row['kind'], $row['remoteId'])] = $row['subscriptionId'];
        }

        return $subscribed;
    }

    /** @return array<string, PrivateFeedModel> */
    private static function byKey(NetworkCollectionsModel $network): array
    {
        $collections = [
            ...array_map(
                static fn (SocialCollectionModel $list): PrivateFeedModel => PrivateFeedModel::userList(
                    $list->id,
                    $list->name,
                ),
                $network->lists,
            ),
            ...array_map(
                static fn (SocialCollectionModel $feed): PrivateFeedModel => PrivateFeedModel::customFeed(
                    $feed->id,
                    $feed->name,
                ),
                $network->customFeeds,
            ),
        ];
        $byKey = [];
        foreach ($collections as $collection) {
            $byKey[$collection->key()] = $collection;
        }

        return $byKey;
    }

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return array<int, string> display title by subscription id, read before the unsubscribe
     */
    private static function titlesOf(array $subscriptions): array
    {
        $titles = [];
        foreach ($subscriptions as $subscription) {
            $feed = $subscription->getFeed();
            $titles[$subscription->requireId()] = SubscriptionDisplayTitle::from(
                $subscription->getCustomTitle(),
                $feed->getTitle(),
                $feed->getUrl(),
            );
        }

        return $titles;
    }
}
