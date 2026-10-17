<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\SubscribedCollectionModel;

final readonly class CollectionSyncPlan
{
    /** @var list<PrivateFeedModel> */
    private array $additions;

    /** @var array<int, PrivateFeedModel> collection by subscription id */
    private array $removals;

    /** @var list<PrivateFeedModel> */
    private array $toRemember;

    /**
     * @param array<string, PrivateFeedModel>          $additions        by key
     * @param array<string, SubscribedCollectionModel> $leaving          by key
     * @param array<string, PrivateFeedModel>          $unknownOnNetwork by key
     */
    private function __construct(array $additions, array $leaving, array $unknownOnNetwork)
    {
        $this->additions = array_values($additions);
        $this->removals = self::collectionsBySubscriptionId($leaving);
        $this->toRemember = array_values(array_diff_key($unknownOnNetwork, $additions));
    }

    /**
     * The account's first complete check: everything on the network is remembered, so what the user has not
     * subscribed counts as dropped, and nothing is added.
     *
     * @param array<string, PrivateFeedModel>          $onNetwork  by key
     * @param array<string, SubscribedCollectionModel> $subscribed by key
     * @param array<string, true>                      $known
     */
    public static function baseline(array $onNetwork, array $subscribed, array $known): self
    {
        return new self([], array_diff_key($subscribed, $onNetwork), array_diff_key($onNetwork, $known));
    }

    /**
     * @param array<string, PrivateFeedModel>          $onNetwork  by key
     * @param array<string, SubscribedCollectionModel> $subscribed by key
     * @param array<string, true>                      $known
     */
    public static function following(array $onNetwork, array $subscribed, array $known): self
    {
        $unknownOnNetwork = array_diff_key($onNetwork, $known);

        return new self(
            array_diff_key($unknownOnNetwork, $subscribed),
            array_diff_key($subscribed, $onNetwork),
            $unknownOnNetwork,
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
