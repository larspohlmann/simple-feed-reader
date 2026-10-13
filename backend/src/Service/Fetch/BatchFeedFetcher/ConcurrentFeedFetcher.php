<?php

declare(strict_types=1);

namespace App\Service\Fetch\BatchFeedFetcher;

use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\RequestTicketModel;

/**
 * Fetches many feeds at once on the shared request engine; the refresh sweep is network-wait-bound, so requests
 * overlap while the caller still processes results one at a time.
 */
final readonly class ConcurrentFeedFetcher implements BatchFeedFetcherInterface
{
    public function __construct(private ConcurrentRequestEngine $engine, private int $hostConcurrency)
    {
        if ($hostConcurrency < 1) {
            throw new \InvalidArgumentException(
                sprintf('Per-host concurrency must be at least 1, got %d.', $hostConcurrency),
            );
        }
    }

    /**
     * @param iterable<int|string, FetchTicketModel> $tickets
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    public function fetchAll(iterable $tickets): \Generator
    {
        return $this->engine->sendAll($this->requestTickets($tickets), $this->hostConcurrency);
    }

    /**
     * One ticket at a time: the source is budget-gated, and mapping ahead would start feeds no slot has opened for.
     *
     * @param iterable<int|string, FetchTicketModel> $tickets
     *
     * @return \Generator<int|string, RequestTicketModel>
     */
    private function requestTickets(iterable $tickets): \Generator
    {
        foreach ($tickets as $key => $ticket) {
            yield $key => RequestTicketModel::feed($ticket);
        }
    }
}
