<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Enum\SocialFeedKind;
use App\Repository\RetentionRepository;
use App\Repository\SocialFeedRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\EntryIndexer;
use App\Service\Library\Subscription\Exception\AlreadySubscribedException;
use App\Service\Social\Social\Exception\NotASearchFeedException;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\PrivateFeedRule\PrivateFeedRuleInterface;
use App\Service\Social\Social\Support\SocialFeedUrl;
use App\Service\Social\Social\Support\SocialSearchMatch;
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
        private PrivateFeedRules $privateFeedRules,
    ) {
    }

    /**
     * @throws NotASearchFeedException
     * @throws AlreadySubscribedException when the account already searches for the query
     * @throws SearchQueryTooBroadException when the network cannot search the query
     */
    public function requery(Subscription $subscription, string $query): void
    {
        $socialFeed = $this->requireSearchFeed($subscription);
        $search = PrivateFeedModel::search($query);
        if ($search->remoteId === $socialFeed->getRemoteId()) {
            return;
        }
        $this->refuseAnotherSearchFor($socialFeed, $search);
        $this->refuseTooBroadFor($socialFeed, $search);
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

    private function requireSearchFeed(Subscription $subscription): SocialFeed
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

    /** @throws SearchQueryTooBroadException */
    private function refuseTooBroadFor(SocialFeed $socialFeed, PrivateFeedModel $search): void
    {
        $this->selectRule($socialFeed)->buildSearchQueries($search->remoteId);
    }

    private function retarget(SocialFeed $socialFeed, PrivateFeedModel $search): void
    {
        $account = $socialFeed->getSocialAccount();
        $feed = $socialFeed->getFeed();
        $socialFeed->requery($search->remoteId);
        $feed->setUrl(SocialFeedUrl::buildUrl($account, $search));
        $feed->setSiteUrl($this->selectRule($socialFeed)->buildSiteUrl($account, $search));
        $feed->setTitle($search->remoteId);
        $feed->scheduleNextFetchAt($this->clock->now());
    }

    /** @return list<int> the ids of the feed's unprotected posts the new query no longer matches */
    private function nonMatchingIds(SocialFeed $socialFeed, PrivateFeedModel $search): array
    {
        $account = $socialFeed->getSocialAccount();
        $rule = $this->selectRule($socialFeed);
        $matches = SocialSearchMatch::matcher($search->remoteId);
        $nonMatchingIds = [];
        $lastEntryId = 0;
        do {
            $posts = $this->socialFeeds->unprotectedPostsAfter($socialFeed, $lastEntryId);
            foreach ($posts as $post) {
                if (!$matches($rule->buildSearchedPost($account, $post))) {
                    $nonMatchingIds[] = $post->entryId;
                }
                $lastEntryId = $post->entryId;
            }
        } while ([] !== $posts);

        return $nonMatchingIds;
    }

    private function selectRule(SocialFeed $socialFeed): PrivateFeedRuleInterface
    {
        return $this->privateFeedRules->selectRule($socialFeed->getSocialAccount()->getNetwork());
    }
}
