<?php

declare(strict_types=1);

namespace App\Service\Fetch\BatchFeedFetcher;

use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchTicketModel;

interface BatchFeedFetcherInterface
{
    /**
     * Fetch many feeds concurrently, with SSRF protection and conditional-GET
     * support, yielding each result under its ticket's key as soon as it lands.
     *
     * Never throws for an individual feed: a failure arrives as a FetchOutcomeModel
     * carrying its exception, so one bad feed cannot abandon the others.
     * Abandoning the returned iterator cancels whatever is still in flight.
     *
     * @param iterable<int|string, FetchTicketModel> $tickets
     *
     * @return iterable<int|string, FetchOutcomeModel>
     */
    public function fetchAll(iterable $tickets): iterable;
}
