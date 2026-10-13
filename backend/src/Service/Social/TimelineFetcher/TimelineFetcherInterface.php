<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineFetcher;

use App\Entity\SocialFeed;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;

interface TimelineFetcherInterface
{
    /**
     * Each private feed's outcome under its feed id, as its answer lands; abandoning the iterator cancels what is in
     * flight.
     *
     * @param iterable<int, SocialFeed> $socialFeeds by feed id, their accounts loaded; pulled one at a time, as a
     *                                               request may start
     *
     * @return iterable<int|string, TimelineOutcomeModelInterface>
     */
    public function fetchAll(iterable $socialFeeds): iterable;
}
