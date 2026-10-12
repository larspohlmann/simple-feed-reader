<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Refresh\Pass\RefreshPass;

/** Stores each due feed's outcome as it lands: fetched feeds through the batch engine, private ones per account. */
final readonly class RefreshPassFetcher
{
    public function __construct(
        private BatchFeedFetcherInterface $fetcher,
        private FeedOutcomePersister $outcomePersister,
        private SocialFeedRefresher $socialRefresher,
    ) {
    }

    /** @throws \DateMalformedStringException */
    public function fetchAndPersist(RefreshPass $pass, \DateTimeImmutable $now): void
    {
        foreach ($this->fetcher->fetchAll($pass->tickets()) as $feedId => $outcome) {
            $feed = $pass->feed($feedId);
            $pass->tally->record($this->outcomePersister->persist($feed, $outcome, $now), $feed);
            if ($pass->tally->isAborted()) {
                return;
            }
        }

        foreach ($pass->socialFeeds() as $feed) {
            $pass->tally->record($this->socialRefresher->refresh($feed, $now), $feed);
            if ($pass->tally->isAborted()) {
                return;
            }
        }
    }
}
