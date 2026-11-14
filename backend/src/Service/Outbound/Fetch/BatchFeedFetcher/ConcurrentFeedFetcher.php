<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\BatchFeedFetcher;

use App\Service\Outbound\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Outbound\Fetch\ConcurrentRequestEngine;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Outbound\Fetch\Model\FetchTicketModel;
use App\Service\Outbound\Fetch\Model\RequestTicketModel;
use App\Service\Outbound\Fetch\Support\CapacityGuard;

/** Fetches many feeds at once on the shared request engine; the caller still processes results one at a time. */
final readonly class ConcurrentFeedFetcher implements BatchFeedFetcherInterface
{
    /** @param AnswerSettlerInterface<FetchOutcomeModel> $settler */
    public function __construct(
        private ConcurrentRequestEngine $engine,
        private int $hostConcurrency,
        private AnswerSettlerInterface $settler,
    ) {
        CapacityGuard::atLeastOne($hostConcurrency, 'Per-host concurrency');
    }

    /**
     * @param iterable<int|string, FetchTicketModel> $tickets
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    public function fetchAll(iterable $tickets): \Generator
    {
        return $this->engine->sendAll($this->requestTickets($tickets), $this->settler);
    }

    /**
     * One ticket at a time, so a lazy source is never read ahead of the engine's free slots.
     *
     * @param iterable<int|string, FetchTicketModel> $tickets
     *
     * @return \Generator<int|string, RequestTicketModel>
     */
    private function requestTickets(iterable $tickets): \Generator
    {
        foreach ($tickets as $key => $ticket) {
            yield $key => RequestTicketModel::feed($ticket, $this->hostConcurrency);
        }
    }
}
