<?php

declare(strict_types=1);

namespace App\Service\Refresh\PassFetcher;

use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;

interface PassFetcherInterface
{
    /**
     * Each started feed's outcome under its feed id, as it lands: a fetch outcome for an RSS or Atom feed, a timeline
     * outcome for a private feed. Every started feed yields exactly one. Abandoning the iterator cancels what is in
     * flight.
     *
     * @return iterable<int|string, FetchOutcomeModel|TimelineOutcomeModelInterface>
     */
    public function fetchAll(PassFeeds $feeds): iterable;
}
