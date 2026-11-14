<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class CollectionCandidateModel
{
    private function __construct(
        public PrivateFeedModel $privateFeed,
        public ImportCandidateState $state,
        public ?int $subscriptionId,
    ) {
    }

    /** @param array<string, int> $subscriptionIds the account's private-feed subscriptions by PrivateFeedModel::key() */
    public static function of(PrivateFeedModel $privateFeed, array $subscriptionIds): self
    {
        $subscriptionId = $subscriptionIds[$privateFeed->key()] ?? null;

        return new self(
            $privateFeed,
            null === $subscriptionId ? ImportCandidateState::New : ImportCandidateState::Subscribed,
            $subscriptionId,
        );
    }
}
