<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\Model\PassOutcome;

use App\Entity\Feed;
use App\Service\Ingestion\Refresh\Model\FeedRefreshResultModel;
use App\Service\Ingestion\Refresh\Pass\PassOutcomeStore;
use App\Service\Social\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;

/** A private feed's timeline outcome. */
final readonly class TimelinePassOutcomeModel implements PassOutcomeModelInterface
{
    public function __construct(public TimelineOutcomeModelInterface $outcome)
    {
    }

    /** @throws \DateMalformedStringException */
    public function storeIn(PassOutcomeStore $store, Feed $feed): FeedRefreshResultModel
    {
        return $store->storeTimeline($feed, $this->outcome);
    }
}
