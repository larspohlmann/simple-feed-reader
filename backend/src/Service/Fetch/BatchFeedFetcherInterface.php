<?php

declare(strict_types=1);

namespace App\Service\Fetch;

interface BatchFeedFetcherInterface
{
    /**
     * Fetch many feeds concurrently, with SSRF protection and conditional-GET
     * support, yielding each result under its ticket's key as soon as it lands.
     *
     * Never throws for an individual feed: a failure arrives as a FetchOutcome
     * carrying its exception, so one bad feed cannot abandon the others.
     * Abandoning the returned iterator cancels whatever is still in flight.
     *
     * @param iterable<int|string, FetchTicket> $tickets
     *
     * @return iterable<int|string, FetchOutcome>
     */
    public function fetchAll(iterable $tickets): iterable;
}
