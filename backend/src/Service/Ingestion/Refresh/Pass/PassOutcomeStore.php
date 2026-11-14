<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\AccountThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FetchedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\ThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;

/** Stores one refresh's outcomes as they land, each as of the moment the refresh started. */
final readonly class PassOutcomeStore
{
    public function __construct(private FeedOutcomePersister $outcomePersister, private \DateTimeImmutable $now)
    {
    }

    /** @throws \DateMalformedStringException */
    public function storeFetched(Feed $feed, FetchOutcomeModel $outcome): FeedRefreshResultModel
    {
        return $this->outcomePersister->persist($feed, $outcome, $this->now);
    }

    /** @throws \DateMalformedStringException */
    public function storeTimeline(Feed $feed, TimelineOutcomeModelInterface $outcome): FeedRefreshResultModel
    {
        return match (true) {
            $outcome instanceof FetchedTimelineModel => $this->outcomePersister->storeTimeline(
                $feed,
                $outcome->page,
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
