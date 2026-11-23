<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh;

use App\Repository\SocialFeedRepository;
use App\Service\Ingestion\Refresh\Pass\PassOutcomeStore;
use App\Service\Ingestion\Refresh\Pass\PassTickets;
use App\Service\Ingestion\Refresh\Pass\RefreshPass;
use App\Service\Ingestion\Refresh\Pass\SweepLockKeepalive;
use App\Service\Ingestion\Refresh\PassFetcher\PassFetcherInterface;

/** Stores each of a pass's outcomes as it lands, whatever its feed's kind, one at a time on the one EntityManager. */
final readonly class RefreshPassRecorder
{
    public function __construct(
        private PassFetcherInterface $fetcher,
        private FeedOutcomePersister $outcomePersister,
        private SocialFeedRepository $socialFeeds,
    ) {
    }

    /** @throws \DateMalformedStringException */
    public function record(RefreshPass $pass, \DateTimeImmutable $now, SweepLockKeepalive $keepalive): void
    {
        $outcomes = new PassOutcomeStore($this->outcomePersister, $now);
        $socialFeedsByFeedId = $this->socialFeeds->findWithAccountByFeedIds(PassTickets::collectPrivateFeedIds($pass));
        foreach ($this->fetcher->fetchAll($pass, $socialFeedsByFeedId) as $feedId => $outcome) {
            $feed = $pass->feed($feedId);
            $pass->tally->record($outcome->storeIn($outcomes, $feed), $feed);
            $keepalive->beat();
            $pass->releaseFeed($feed->requireId());
            if ($pass->tally->isAborted()) {
                return;
            }
        }
    }
}
