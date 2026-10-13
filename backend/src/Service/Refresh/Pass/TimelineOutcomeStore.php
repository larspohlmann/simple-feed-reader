<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\Model\IncomingFeedModel;
use App\Service\Social\Model\TimelineOutcome;
use App\Service\Social\Model\TimelineOutcomeModel;

/** Stores one refresh's private-feed outcomes, each as of the moment the refresh started. */
final readonly class TimelineOutcomeStore
{
    public function __construct(private FeedOutcomePersister $outcomePersister, private \DateTimeImmutable $now)
    {
    }

    /** @throws \DateMalformedStringException */
    public function store(Feed $feed, TimelineOutcomeModel $outcome): FeedRefreshResultModel
    {
        return match ($outcome->outcome) {
            TimelineOutcome::Fetched => $this->outcomePersister->storeParsed(
                $feed,
                new IncomingFeedModel($outcome->page()->parsed, $outcome->page()->knownShares),
                $this->now,
            ),
            TimelineOutcome::Throttled => $this->outcomePersister->storeThrottled($feed, $outcome->retryAfterSeconds),
            TimelineOutcome::Failed => $this->outcomePersister->storeFailure($feed, $outcome->failure()),
            // Held back this refresh: the feed keeps its schedule for the next one.
            TimelineOutcome::AccountThrottled => FeedRefreshResultModel::of(FeedOutcome::Throttled),
            TimelineOutcome::AccountPaused => FeedRefreshResultModel::of(FeedOutcome::Failed),
        };
    }
}
