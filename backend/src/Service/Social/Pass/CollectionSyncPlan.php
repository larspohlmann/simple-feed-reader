<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Service\Social\Model\PrivateFeedModel;

final readonly class CollectionSyncPlan
{
    /**
     * @param list<PrivateFeedModel> $additions
     * @param array<string, int>     $removals   subscription id by key
     * @param list<PrivateFeedModel> $toRemember
     */
    private function __construct(
        private array $additions,
        private array $removals,
        private array $toRemember,
    ) {
    }

    /**
     * The account's first complete check: everything on the network is remembered, so what the user has not
     * subscribed counts as dropped, and nothing is added.
     *
     * @param array<string, PrivateFeedModel> $onNetwork  by key
     * @param array<string, int>              $subscribed subscription id by key
     * @param array<string, true>             $known
     */
    public static function baseline(array $onNetwork, array $subscribed, array $known): self
    {
        return new self([], array_diff_key($subscribed, $onNetwork), array_values(array_diff_key($onNetwork, $known)));
    }

    /**
     * @param array<string, PrivateFeedModel> $onNetwork  by key
     * @param array<string, int>              $subscribed subscription id by key
     * @param array<string, true>             $known
     */
    public static function following(array $onNetwork, array $subscribed, array $known): self
    {
        $additions = array_diff_key($onNetwork, $subscribed, $known);

        return new self(
            array_values($additions),
            array_diff_key($subscribed, $onNetwork),
            array_values(array_diff_key($onNetwork, $additions, $known)),
        );
    }

    /** @return list<PrivateFeedModel> */
    public function additions(): array
    {
        return $this->additions;
    }

    /** @return array<string, int> subscription id by key */
    public function removals(): array
    {
        return $this->removals;
    }

    /** @return list<PrivateFeedModel> on the network, not yet known, not added: an addition remembers itself */
    public function toRemember(): array
    {
        return $this->toRemember;
    }
}
