<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Service\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\SettlementModel;
use App\Service\Refresh\Model\PassOutcome\PassOutcomeModelInterface;

/**
 * One pass's engine tickets in due order and the engine's settler: every call goes to the key's lane.
 *
 * @implements AnswerSettlerInterface<PassOutcomeModelInterface>
 */
final readonly class PassTickets implements AnswerSettlerInterface
{
    public function __construct(private PassFeeds $feeds)
    {
    }

    /** @return \Generator<int|string, RequestTicketModel> */
    public function tickets(): \Generator
    {
        foreach ($this->feeds->started() as $feedId => $feed) {
            yield from $this->feeds->laneOf($feedId)->ticketsFor($feed);
        }
    }

    public function admits(int|string $key): bool
    {
        return $this->feeds->laneOf($key)->admits($key);
    }

    /** @return SettlementModel<PassOutcomeModelInterface> */
    public function settle(int|string $key, FetchOutcomeModel $outcome): SettlementModel
    {
        return $this->feeds->laneOf($key)->settle($key, $outcome);
    }

    /** @return \Generator<int|string, PassOutcomeModelInterface> */
    public function drainUnasked(): \Generator
    {
        foreach ($this->feeds->lanes() as $lane) {
            yield from $lane->drainUnasked();
        }
    }
}
