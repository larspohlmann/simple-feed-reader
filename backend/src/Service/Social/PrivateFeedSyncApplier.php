<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\PrivateFeedSyncChange;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Enum\PrivateFeedSyncReason;
use App\Enum\SocialFeedKind;
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
use App\Service\Social\Model\SubscribedCollectionModel;
use App\Service\Social\Pass\CollectionSyncPlan;
use App\Service\Social\Support\PrivateFeedSyncReasons;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PrivateFeedSyncApplier
{
    private const int KEPT_CHANGES = 10;

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
            $this->trimLogAfter($account, $outcome);
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

    /** @param array<int, PrivateFeedModel> $removals collection by subscription id */
    private function remove(SocialAccount $account, NetworkCollectionsModel $network, array $removals): int
    {
        $leaving = $this->subscriptions->findAllByIdsForUser($account->getUser()->requireId(), array_keys($removals));
        $titles = self::titlesOf($leaving);
        $this->subscriptionService->unsubscribeAll($leaving);
        $forgotten = [];
        foreach ($titles as $subscriptionId => $title) {
            $collection = $removals[$subscriptionId];
            $forgotten[] = $collection;
            $this->log($account, $collection, $title, PrivateFeedSyncReasons::removed($account, $collection, $network));
        }
        $this->knownCollections->forget($account, $forgotten);
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
    }

    private function trimLogAfter(SocialAccount $account, PrivateFeedSyncOutcomeModel $outcome): void
    {
        if (0 === $outcome->added + $outcome->removed) {
            return;
        }
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

    /** @return array<string, SubscribedCollectionModel> by key, of the allow-listed kinds only */
    private function subscribedOf(SocialAccount $account): array
    {
        $subscribed = [];
        foreach ($this->socialFeeds->subscribedCollectionsOfKinds($account, PrivateFeedSync::SYNCED_KINDS) as $row) {
            $collection = PrivateFeedModel::of($row['kind'], $row['remoteId'], '');
            $subscribed[$collection->key()] = new SubscribedCollectionModel($row['subscriptionId'], $collection);
        }

        return $subscribed;
    }

    /** @return array<string, PrivateFeedModel> */
    private static function byKey(NetworkCollectionsModel $network): array
    {
        $collections = [
            ...self::modelsOf(SocialFeedKind::UserList, $network->lists),
            ...self::modelsOf(SocialFeedKind::CustomFeed, $network->customFeeds),
            ...self::modelsOf(SocialFeedKind::Tag, $network->tags),
        ];
        $byKey = [];
        foreach ($collections as $collection) {
            $byKey[$collection->key()] = $collection;
        }

        return $byKey;
    }

    /**
     * @param list<SocialCollectionModel> $collections
     *
     * @return list<PrivateFeedModel>
     */
    private static function modelsOf(SocialFeedKind $kind, array $collections): array
    {
        return array_map(
            static fn (SocialCollectionModel $collection): PrivateFeedModel => PrivateFeedModel::of(
                $kind,
                $collection->id,
                $collection->name,
            ),
            $collections,
        );
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
            $titles[$subscription->requireId()] = SubscriptionDisplayTitle::ofSubscription($subscription);
        }

        return $titles;
    }
}
