<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass\PassLane;

use App\Entity\Feed;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\SettlementModel;
use App\Service\Refresh\Model\PassOutcome\PassOutcomeModelInterface;

/** How one kind of feed goes through a pass: its tickets, the gate, its answers, and what settles without a request. */
interface PassLaneInterface
{
    /** @return \Generator<int|string, RequestTicketModel> none when the feed settles without a request */
    public function ticketsFor(Feed $feed): \Generator;

    public function admits(int|string $feedId): bool;

    /** @return SettlementModel<PassOutcomeModelInterface> */
    public function settle(int|string $feedId, FetchOutcomeModel $outcome): SettlementModel;

    /** @return \Generator<int|string, PassOutcomeModelInterface> */
    public function drainUnasked(): \Generator;
}
