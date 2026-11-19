<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\Pass;

use App\Enum\SourceFormat;
use App\Service\Ingestion\Refresh\Model\PassOutcome\PassOutcomeModelInterface;
use App\Service\Ingestion\Refresh\Pass\PassLane\PassLaneInterface;
use App\Service\Outbound\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Outbound\Fetch\Model\RequestTicketModel;
use App\Service\Outbound\Fetch\Model\SettlementModel;

/**
 * One pass's engine tickets in due order and the engine's settler: each feed id's lane is decided once, here,
 * and every call for a key goes to its lane.
 *
 * @implements AnswerSettlerInterface<PassOutcomeModelInterface>
 */
final readonly class PassTickets implements AnswerSettlerInterface
{
    /** @var array<int, true> */
    private array $privateFeedIds;

    public function __construct(
        private RefreshPass $pass,
        private PassLaneInterface $feedLane,
        private PassLaneInterface $timelineLane,
    ) {
        $this->privateFeedIds = array_fill_keys(self::collectPrivateFeedIds($pass), true);
    }

    /** @return list<int> every private feed's id, started or not */
    public static function collectPrivateFeedIds(RefreshPass $pass): array
    {
        $privateFeedIds = [];
        foreach ($pass->feeds as $feed) {
            if (SourceFormat::isSocial($feed->getSourceFormat())) {
                $privateFeedIds[] = $feed->requireId();
            }
        }

        return $privateFeedIds;
    }

    /** @return \Generator<int|string, RequestTicketModel> */
    public function tickets(): \Generator
    {
        foreach ($this->pass->startedFeeds() as $feedId => $feed) {
            yield from $this->selectLane($feedId)->ticketsFor($feed);
        }
    }

    public function admits(int|string $key): bool
    {
        return $this->selectLane($key)->admits($key);
    }

    /** @return SettlementModel<PassOutcomeModelInterface> */
    public function settle(int|string $key, FetchOutcomeModel $outcome): SettlementModel
    {
        return $this->selectLane($key)->settle($key, $outcome);
    }

    /** @return \Generator<int|string, PassOutcomeModelInterface> */
    public function drainUnasked(): \Generator
    {
        yield from $this->feedLane->drainUnasked();
        yield from $this->timelineLane->drainUnasked();
    }

    private function selectLane(int|string $feedId): PassLaneInterface
    {
        return isset($this->privateFeedIds[$feedId]) ? $this->timelineLane : $this->feedLane;
    }
}
