<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SocialAccountStatus;
use App\Repository\SocialFeedRepository;
use App\Service\Refresh\Exception\PrivateFeedOrphanedException;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\Model\IncomingFeedModel;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
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
    public function refresh(Feed $feed, \DateTimeImmutable $now): FeedRefreshResultModel
    {
        $socialFeed = $this->socialFeeds->findOneByFeed($feed);
        if (null === $socialFeed) {
            return $this->outcomePersister->storeFailure($feed, new PrivateFeedOrphanedException());
        }

        try {
            $page = $this->pageOf($socialFeed);
        } catch (SocialNetworkThrottledException $exception) {
            return $this->outcomePersister->storeThrottled($feed, $exception->retryAfterSeconds);
        } catch (
            SocialAccountNeedsReconnectException |
            SocialCredentialsRejectedException |
            SocialNetworkUnreachableException $exception
        ) {
            return $this->outcomePersister->storeFailure($feed, $exception);
        }

        // Set before the store's flush, which writes it with the page's entries, or not at all.
        $socialFeed->recordNewestSeen($page->newestId);

        return $this->outcomePersister->storeParsed(
            $feed,
            new IncomingFeedModel($page->parsed, $page->knownShares),
            $now,
        );
    }

    /**
     * An account refused earlier in this pass is not asked again: findDue() chose its feeds before the refusal.
     *
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    private function pageOf(SocialFeed $socialFeed): TimelinePageModel
    {
        $account = $socialFeed->getSocialAccount();
        if (SocialAccountStatus::NeedsReconnect === $account->getStatus()) {
            throw new SocialAccountNeedsReconnectException(sprintf('%s needs reconnecting.', $account->getHandle()));
        }

        try {
            return $this->timelines->page($socialFeed);
        } catch (SocialCredentialsRejectedException $exception) {
            $account->markNeedsReconnect();

            throw $exception;
        }
    }
}
