<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Enum\SocialFeedKind;
use App\Repository\RetentionRepository;
use App\Repository\SocialFeedRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Search\EntryIndexer;
use App\Service\Social\Exception\NotASearchFeedException;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Support\PrivateFeedSite;
use App\Service\Social\Support\SocialFeedUrl;
use App\Service\Social\Support\SocialSearchMatch;
use App\Service\Social\Support\StoredSearchedPost;
use App\Service\Subscription\Exception\AlreadySubscribedException;
use Doctrine\ORM\EntityManagerInterface;

/** Points a search feed at another query in place, its stored posts that no longer match removed unless protected. */
final readonly class SearchFeedEditor
{
    public function __construct(
        private SocialFeedRepository $socialFeeds,
        private RetentionRepository $retention,
        private EntryIndexer $indexer,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    /**
     * @throws NotASearchFeedException
     * @throws AlreadySubscribedException when the account already searches for the query
     */
    public function requery(Subscription $subscription, string $query): void
    {
        $socialFeed = $this->searchFeedOf($subscription);
        $search = PrivateFeedModel::search($query);
        if ($search->remoteId === $socialFeed->getRemoteId()) {
            return;
        }
        $this->refuseAnotherSearchFor($socialFeed, $search);
        $nonMatchingIds = $this->nonMatchingIds($socialFeed, $search);

        $removedIds = $this->entityManager->wrapInTransaction(
            function () use ($socialFeed, $search, $nonMatchingIds): array {
                $this->retarget($socialFeed, $search);
                $this->entityManager->flush();

                return $this->retention->deleteUnprotectedEntries($nonMatchingIds);
            },
        );
        $this->indexer->forget($removedIds);
    }

    private function searchFeedOf(Subscription $subscription): SocialFeed
    {
        $socialFeed = $this->socialFeeds->forFeed($subscription->getFeed());
        if (null === $socialFeed || SocialFeedKind::Search !== $socialFeed->getKind()) {
            throw new NotASearchFeedException('Only a search feed has a query to change.');
        }

        return $socialFeed;
    }

    /** MySQL compares the query without regard to case, so the feed itself may be what it finds. */
    private function refuseAnotherSearchFor(SocialFeed $socialFeed, PrivateFeedModel $search): void
    {
        $existing = $this->socialFeeds->findOneFor($socialFeed->getSocialAccount(), $search->kind, $search->remoteId);
        if (null !== $existing && $existing !== $socialFeed) {
            throw new AlreadySubscribedException('This account already searches for that query.');
        }
    }

    private function retarget(SocialFeed $socialFeed, PrivateFeedModel $search): void
    {
        $account = $socialFeed->getSocialAccount();
        $feed = $socialFeed->getFeed();
        $socialFeed->requery($search->remoteId);
        $feed->setUrl(SocialFeedUrl::of($account, $search));
        $feed->setSiteUrl(PrivateFeedSite::of($account, $search));
        $feed->setTitle($search->remoteId);
        $feed->scheduleNextFetchAt($this->clock->now());
    }

    /** @return list<int> the ids of the feed's unprotected posts the new query no longer matches */
    private function nonMatchingIds(SocialFeed $socialFeed, PrivateFeedModel $search): array
    {
        $account = $socialFeed->getSocialAccount();
        $matches = SocialSearchMatch::matcher($search->remoteId);
        $nonMatchingIds = [];
        $lastEntryId = 0;
        do {
            $posts = $this->socialFeeds->unprotectedPostsAfter($socialFeed, $lastEntryId);
            foreach ($posts as $post) {
                if (!$matches(StoredSearchedPost::of($account, $post))) {
                    $nonMatchingIds[] = $post->entryId;
                }
                $lastEntryId = $post->entryId;
            }
        } while ([] !== $posts);

        return $nonMatchingIds;
    }
}
