<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Service\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\SettlementModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\Pass\TimelineRound;

/**
 * One pass's engine tickets in due order, an RSS or Atom feed by its URL, a private feed through its round, and the
 * engine's settler. Only a held account's private tickets are withheld.
 *
 * @implements AnswerSettlerInterface<FetchOutcomeModel|TimelineOutcomeModelInterface|null>
 */
final readonly class PassTickets implements AnswerSettlerInterface
{
    public function __construct(
        private PassFeeds $feeds,
        private TimelineRound $round,
        private int $hostCapacity,
    ) {
    }

    /** @return \Generator<int|string, RequestTicketModel> */
    public function tickets(): \Generator
    {
        foreach ($this->feeds->started() as $feedId => $feed) {
            if ($this->feeds->isPrivate($feedId)) {
                yield from $this->round->ticketFor($feedId);
                continue;
            }

            yield $feedId => RequestTicketModel::feed(
                new FetchTicketModel($feed->getUrl(), $feed->getEtag(), $feed->getLastModified()),
                $this->hostCapacity,
            );
        }
    }

    public function admits(int|string $key): bool
    {
        return !$this->feeds->isPrivate($key) || $this->round->admits($key);
    }

    /**
     * @return SettlementModel<FetchOutcomeModel|TimelineOutcomeModelInterface|null> null while a private feed waits
     *     for the renewal wave
     */
    public function settle(int|string $key, FetchOutcomeModel $outcome): SettlementModel
    {
        return SettlementModel::outcome(
            $this->feeds->isPrivate($key) ? $this->round->settle($key, $outcome) : $outcome,
        );
    }

    /** @return \Generator<int|string, TimelineOutcomeModelInterface> */
    public function drainUnasked(): \Generator
    {
        return $this->round->drainUnasked();
    }
}
