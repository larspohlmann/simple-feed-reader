<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineFetcher;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Service\Social\Model\TimelineOutcomeModel;

interface TimelineFetcherInterface
{
    /**
     * Each private feed's outcome under its feed id, as its answer lands; abandoning the iterator cancels what is in
     * flight.
     *
     * @param array<int, SocialFeed> $socialFeedsByFeedId the refresh's private feeds, their accounts loaded
     * @param iterable<int, Feed>    $feeds               pulled one at a time, as a request may start
     *
     * @return iterable<int, TimelineOutcomeModel>
     */
    public function fetchAll(array $socialFeedsByFeedId, iterable $feeds): iterable;
}
