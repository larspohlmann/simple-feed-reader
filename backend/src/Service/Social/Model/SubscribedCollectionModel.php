<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class SubscribedCollectionModel
{
    public function __construct(
        public int $subscriptionId,
        public PrivateFeedModel $collection,
    ) {
    }
}
