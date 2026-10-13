<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\Model\IncomingFeedModel;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\AccountThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FetchedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\ThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;

/** Stores one refresh's private-feed outcomes, each as of the moment the refresh started. */
final readonly class TimelineOutcomeStore
{
    public function __construct(private FeedOutcomePersister $outcomePersister, private \DateTimeImmutable $now)
    {
    }

    /** @throws \DateMalformedStringException */
    public function store(Feed $feed, TimelineOutcomeModelInterface $outcome): FeedRefreshResultModel
    {
        return match (true) {
            $outcome instanceof FetchedTimelineModel => $this->outcomePersister->storeParsed(
                $feed,
                new IncomingFeedModel($outcome->page->parsed, $outcome->page->knownShares),
                $this->now,
            ),
            $outcome instanceof ThrottledTimelineModel => $this->outcomePersister->storeThrottled(
                $feed,
                $outcome->retryAfterSeconds,
            ),
            $outcome instanceof FailedTimelineModel => $this->outcomePersister->storeFailure($feed, $outcome->failure),
            // Held back this refresh: the feed keeps its schedule for the next one.
            $outcome instanceof AccountThrottledTimelineModel => FeedRefreshResultModel::of(FeedOutcome::Throttled),
            $outcome instanceof AccountPausedTimelineModel => FeedRefreshResultModel::of(FeedOutcome::Failed),
        };
    }
}
