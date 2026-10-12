<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SocialAccountStatus;
use App\Repository\SocialFeedRepository;
use App\Service\Refresh\Exception\PrivateFeedOrphanedException;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\Model\IncomingFeedModel;
use App\Service\Refresh\Pass\ThrottledSocialAccounts;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\TimelineReader\TimelineReaderInterface;

/** Polls one private feed through its account and stores the page the way a fetched feed is stored. */
final readonly class SocialFeedRefresher
{
    public function __construct(
        private SocialFeedRepository $socialFeeds,
        private TimelineReaderInterface $timelines,
        private FeedOutcomePersister $outcomePersister,
    ) {
    }

    /** @throws \DateMalformedStringException */
    public function refresh(
        Feed $feed,
        \DateTimeImmutable $now,
        ThrottledSocialAccounts $throttledAccounts,
    ): FeedRefreshResultModel {
        $socialFeed = $this->socialFeeds->findOneByFeed($feed);
        if (null === $socialFeed) {
            return $this->outcomePersister->storeFailure($feed, new PrivateFeedOrphanedException());
        }
        $account = $socialFeed->getSocialAccount();
        // Refused or throttled earlier in this pass: its other feeds keep their schedule for the next one.
        if (SocialAccountStatus::NeedsReconnect === $account->getStatus()) {
            return FeedRefreshResultModel::of(FeedOutcome::Failed);
        }
        if ($throttledAccounts->contains($account)) {
            return FeedRefreshResultModel::of(FeedOutcome::Throttled);
        }

        try {
            $page = $this->pageOf($socialFeed);
        } catch (SocialNetworkThrottledException $exception) {
            $throttledAccounts->add($account);

            return $this->outcomePersister->storeThrottled($feed, $exception->retryAfterSeconds);
        } catch (SocialCredentialsRejectedException | SocialNetworkUnreachableException $exception) {
            return $this->outcomePersister->storeFailure($feed, $exception);
        }

        $socialFeed->recordNewestSeen($page->newestId);

        return $this->outcomePersister->storeParsed(
            $feed,
            new IncomingFeedModel($page->parsed, $page->knownShares),
            $now,
        );
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    private function pageOf(SocialFeed $socialFeed): TimelinePageModel
    {
        try {
            return $this->timelines->page($socialFeed);
        } catch (SocialCredentialsRejectedException $exception) {
            $socialFeed->getSocialAccount()->markNeedsReconnect();

            throw $exception;
        }
    }
}
