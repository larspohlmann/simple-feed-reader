<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Repository\SocialFeedRepository;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\PassOutcomeStore;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Refresh\PassFetcher\PassFetcherInterface;

/** Stores each of a pass's outcomes as it lands, whatever its feed's kind, one at a time on the one EntityManager. */
final readonly class RefreshPassFetcher
{
    public function __construct(
        private PassFetcherInterface $fetcher,
        private FeedOutcomePersister $outcomePersister,
        private SocialFeedRepository $socialFeeds,
    ) {
    }

    /** @throws \DateMalformedStringException */
    public function fetchAndPersist(RefreshPass $pass, \DateTimeImmutable $now): void
    {
        $outcomes = new PassOutcomeStore($this->outcomePersister, $now);
        foreach ($this->fetcher->fetchAll($this->feedsOf($pass)) as $feedId => $outcome) {
            $feed = $pass->feed($feedId);
            $pass->tally->record($outcomes->store($feed, $outcome), $feed);
            if ($pass->tally->isAborted()) {
                return;
            }
        }
    }

    private function feedsOf(RefreshPass $pass): PassFeeds
    {
        return new PassFeeds($pass, $this->socialFeeds->findWithAccountByFeedIds($pass->socialFeedIds()));
    }
}
