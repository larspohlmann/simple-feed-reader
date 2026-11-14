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
     * A baselined kind takes its first complete check: what is on the network is remembered, so what the user has
     * not subscribed counts as dropped, and nothing of that kind is added.
     *
     * @param array<string, PrivateFeedModel>          $onNetwork      by key
     * @param array<string, SubscribedCollectionModel> $subscribed     by key
     * @param array<string, true>                      $known
     * @param list<SocialFeedKind>                     $baselinedKinds
     */
    public static function following(array $onNetwork, array $subscribed, array $known, array $baselinedKinds): self
    {
        $unknownOnNetwork = array_diff_key($onNetwork, $known);
        $additions = [];
        $baselined = [];
        foreach (array_diff_key($unknownOnNetwork, $subscribed) as $collection) {
            if (\in_array($collection->kind, $baselinedKinds, true)) {
                $baselined[] = $collection;
                continue;
            }
            $additions[] = $collection;
        }

        return new self(
            $additions,
            self::collectionsBySubscriptionId(array_diff_key($subscribed, $onNetwork)),
            [...array_values(array_intersect_key($unknownOnNetwork, $subscribed)), ...$baselined],
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
