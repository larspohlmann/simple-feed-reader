<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Enum\SocialFeedKind;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\SubscribedCollectionModel;

final readonly class CollectionSyncPlan
{
    /**
     * @param list<PrivateFeedModel>       $additions
     * @param array<int, PrivateFeedModel> $removals   collection by subscription id
     * @param list<PrivateFeedModel>       $toRemember
     */
    private function __construct(private array $additions, private array $removals, private array $toRemember)
    {
    }

    /**
     * @param array<string, PrivateFeedModel>          $onNetwork  by key
     * @param array<string, SubscribedCollectionModel> $subscribed by key
     * @param array<string, true>                      $known
     */
    public static function following(array $onNetwork, array $subscribed, array $known): self
    {
        $unknownOnNetwork = array_diff_key($onNetwork, $known);
        $additions = array_diff_key($unknownOnNetwork, $subscribed);

        return new self(
            array_values($additions),
            self::collectionsBySubscriptionId(array_diff_key($subscribed, $onNetwork)),
            array_values(array_diff_key($unknownOnNetwork, $additions)),
        );
    }

    /**
     * The given kinds' first complete check: what is on the network is remembered, so what the user has not
     * subscribed counts as dropped, and nothing of those kinds is added.
     *
     * @param list<SocialFeedKind> $kinds
     */
    public function baselining(array $kinds): self
    {
        $isBaselined = static fn (PrivateFeedModel $collection): bool => \in_array($collection->kind, $kinds, true);
        $isKept = static fn (PrivateFeedModel $collection): bool => !$isBaselined($collection);

        return new self(
            array_values(array_filter($this->additions, $isKept)),
            $this->removals,
            [...$this->toRemember, ...array_values(array_filter($this->additions, $isBaselined))],
        );
    }

    /** @return list<PrivateFeedModel> */
    public function additions(): array
    {
        return $this->additions;
    }

    /** @return array<int, PrivateFeedModel> collection by subscription id */
    public function removals(): array
    {
        return $this->removals;
    }

    /** @return list<PrivateFeedModel> on the network, not yet known, not added: an addition remembers itself */
    public function toRemember(): array
    {
        return $this->toRemember;
    }

    /**
     * @param array<string, SubscribedCollectionModel> $leaving
     *
     * @return array<int, PrivateFeedModel>
     */
    private static function collectionsBySubscriptionId(array $leaving): array
    {
        $collections = [];
        foreach ($leaving as $subscribed) {
            $collections[$subscribed->subscriptionId] = $subscribed->collection;
        }

        return $collections;
    }
}
