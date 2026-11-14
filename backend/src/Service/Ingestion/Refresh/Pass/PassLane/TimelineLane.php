<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\Pass\PassLane;

use App\Entity\Feed;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Outbound\Fetch\Model\SettlementModel;
use App\Service\Ingestion\Refresh\Model\PassOutcome\TimelinePassOutcomeModel;
use App\Service\Social\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\Social\Pass\TimelineRound;

/** A private feed: its ticket, gate and answers through the pass's timeline round. */
final readonly class TimelineLane implements PassLaneInterface
{
    public function __construct(private TimelineRound $round)
    {
    }

    public function ticketsFor(Feed $feed): \Generator
    {
        return $this->round->ticketFor($feed->requireId());
    }

    public function admits(int|string $feedId): bool
    {
        return $this->round->admits($feedId);
    }

    /** @return SettlementModel<TimelinePassOutcomeModel> */
    public function settle(int|string $feedId, FetchOutcomeModel $outcome): SettlementModel
    {
        return $this->round->settle($feedId, $outcome)->mapOutcome(
            static fn (TimelineOutcomeModelInterface $timeline): TimelinePassOutcomeModel
                => new TimelinePassOutcomeModel($timeline),
        );
    }

    public function drainUnasked(): \Generator
    {
        foreach ($this->round->drainUnasked() as $feedId => $timeline) {
            yield $feedId => new TimelinePassOutcomeModel($timeline);
        }
    }
}
