<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\Pass\PassLane;

use App\Entity\Feed;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Outbound\Fetch\Model\FetchTicketModel;
use App\Service\Outbound\Fetch\Model\RequestTicketModel;
use App\Service\Outbound\Fetch\Model\SettlementModel;
use App\Service\Ingestion\Refresh\Model\PassOutcome\FeedPassOutcomeModel;

/** An RSS or Atom feed: one conditional request on its host, always admitted, its raw outcome stored as fetched. */
final readonly class FeedLane implements PassLaneInterface
{
    public function __construct(private int $hostCapacity)
    {
    }

    public function ticketsFor(Feed $feed): \Generator
    {
        yield $feed->requireId() => RequestTicketModel::feed(
            new FetchTicketModel($feed->getUrl(), $feed->getEtag(), $feed->getLastModified()),
            $this->hostCapacity,
        );
    }

    public function admits(int|string $feedId): bool
    {
        return true;
    }

    /** @return SettlementModel<FeedPassOutcomeModel> */
    public function settle(int|string $feedId, FetchOutcomeModel $outcome): SettlementModel
    {
        return SettlementModel::outcome(new FeedPassOutcomeModel($outcome));
    }

    public function drainUnasked(): \Generator
    {
        yield from [];
    }
}
