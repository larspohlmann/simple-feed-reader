<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model\PassOutcome;

use App\Entity\Feed;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\Pass\PassOutcomeStore;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;

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
