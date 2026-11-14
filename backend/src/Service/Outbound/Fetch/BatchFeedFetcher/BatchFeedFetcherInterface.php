<?php

declare(strict_types=1);

namespace App\Service\Fetch\BatchFeedFetcher;

use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchTicketModel;

interface BatchFeedFetcherInterface
{
    /**
     * Fetches the feeds concurrently, SSRF-guarded and with conditional GET, yielding each outcome under its ticket's
     * key as it lands. A failed feed arrives as a failed outcome, never a throw, so one bad feed cannot abandon the
     * others; abandoning the iterator cancels whatever is still in flight.
     *
     * @param iterable<int|string, FetchTicketModel> $tickets
     *
     * @return iterable<int|string, FetchOutcomeModel>
     */
    public function fetchAll(iterable $tickets): iterable;
}
