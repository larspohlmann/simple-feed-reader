<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Repository\SocialFeedRepository;
use App\Service\Refresh\Pass\PassOutcomeStore;
use App\Service\Refresh\Pass\PrivateFeedSupply;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Social\TimelineFetcher\TimelineFetcherInterface;

/** Stores each private feed's outcome as it lands, one at a time on the one EntityManager. */
final readonly class SocialFeedRefresher
{
    public function __construct(
        private SocialFeedRepository $socialFeeds,
        private TimelineFetcherInterface $timelines,
        private FeedOutcomePersister $outcomePersister,
    ) {
    }

    /** @throws \DateMalformedStringException */
    public function refreshAll(RefreshPass $pass, \DateTimeImmutable $now): void
    {
        $socialFeedIds = $pass->socialFeedIds();
        if ([] === $socialFeedIds) {
            return;
        }

        $outcomes = new PassOutcomeStore($this->outcomePersister, $now);
        $supply = new PrivateFeedSupply($pass, $this->socialFeeds->findWithAccountByFeedIds($socialFeedIds));
        foreach ($supply->withOrphans($this->timelines->fetchAll($supply->socialFeeds())) as $feedId => $outcome) {
            $feed = $pass->feed($feedId);
            $pass->tally->record($outcomes->store($feed, $outcome), $feed);
            if ($pass->tally->isAborted()) {
                return;
            }
        }
    }
}
