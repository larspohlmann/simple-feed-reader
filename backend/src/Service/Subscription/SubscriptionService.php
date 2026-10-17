<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Service\Discovery\FeedDiscovery\FeedDiscoveryInterface;
use App\Service\Discovery\ScrapeFallbackPolicy;
use App\Service\Feed\OrphanedFeedReclaimer;
use App\Service\SavedPosts\SavedPostRescue;
use App\Service\Subscription\Model\SubscribeOutcomeModel;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SubscriptionService
{
    public const int MAX_SUBSCRIPTIONS_PER_USER = 500;

    /**
     * Bounds one bulk request's payload, not the subscription cap: an admin may raise an account's cap above
     * MAX_SUBSCRIPTIONS_PER_USER, which a validation attribute cannot read. OwnedSubscriptions still checks every id.
     */
    public const int MAX_BULK_REQUEST_IDS = 5000;

    public function __construct(
        private FeedDiscoveryInterface $discovery,
        private SubscriptionCreator $creator,
        private ScrapeFallbackPolicy $scrapeFallbackPolicy,
        private FirstFetchRecorder $firstFetch,
        private OrphanedFeedReclaimer $orphanedFeeds,
        private SavedPostRescue $savedPostRescue,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function unsubscribe(Subscription $subscription): void
    {
        $this->unsubscribeAll([$subscription]);
    }

    /**
     * Saved feeds of the call go first, so the rescue of the others never copies into a saved feed this call deletes.
     * Each half flushes its removal before reclaiming, so the DELETE's no-subscriber guard sees the rows gone.
     *
     * @param list<Subscription> $subscriptions
     */
    public function unsubscribeAll(array $subscriptions): int
    {
        if ([] === $subscriptions) {
            return 0;
        }

        $savedFeeds = array_values(array_filter($subscriptions, self::isSavedFeed(...)));
        $others = array_values(array_filter(
            $subscriptions,
            static fn (Subscription $subscription): bool => !self::isSavedFeed($subscription),
        ));
        $this->removeAfter($savedFeeds, static fn (): array => []);
        $copyIds = $this->removeAfter($others, $this->savedPostRescue->rescueFrom(...));
        $this->savedPostRescue->indexCopies($copyIds);

        return \count($subscriptions);
    }

    /**
     * @param list<Tag> $tags the user-owned tags to attach to a newly created
     *                        subscription; ignored when the outcome is a
     *                        candidate list rather than a subscription
     */
    public function subscribe(
        User $user,
        string $url,
        ?string $format = null,
        array $tags = [],
        ?string $initialTitle = null,
    ): SubscribeOutcomeModel {
        // Re-running discovery on a candidate it just offered could fail this time and block the offered subscribe.
        if (SourceFormat::SCRAPED === $format) {
            // This shortcut skips discovery's scrape gate, so a hand-made request must meet the preference here.
            $this->scrapeFallbackPolicy->assertMayScrape($user);

            return $this->subscribeVerbatim($user, $url, SourceFormat::SCRAPED, $tags);
        }

        if (SourceFormat::WP_JSON === $format) {
            // No permission gate: unlike scraping, a REST endpoint is a real
            // machine source the site publishes, not a synthesized page scrape.
            return $this->subscribeVerbatim($user, $url, SourceFormat::WP_JSON, $tags, $initialTitle);
        }

        $result = $this->discovery->discover($url, $this->scrapeFallbackPolicy->forUser($user));

        $discovered = $result->feed;
        if (null === $discovered) {
            return SubscribeOutcomeModel::candidates($result->candidates, $result->scrapeFailureReason);
        }

        $subscription = $this->creator->create($user, $discovered->url, SourceFormat::XML, $tags);
        $unread = $this->firstFetch->record($subscription->getFeed(), $discovered);

        return SubscribeOutcomeModel::subscribed($subscription, $unread);
    }

    /**
     * @param list<Subscription>                      $subscriptions
     * @param \Closure(list<Subscription>): list<int> $beforeRemoval
     *
     * @return list<int> what $beforeRemoval returned
     */
    private function removeAfter(array $subscriptions, \Closure $beforeRemoval): array
    {
        if ([] === $subscriptions) {
            return [];
        }
        $feedIds = self::feedIdsOf($subscriptions);
        $this->entityManager->beginTransaction();
        try {
            $result = $beforeRemoval($subscriptions);
            foreach ($subscriptions as $subscription) {
                $this->entityManager->remove($subscription);
            }
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }
        foreach ($feedIds as $feedId) {
            $this->orphanedFeeds->reclaim($feedId);
        }

        return $result;
    }

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return list<int> each distinct feed once: two removed subscriptions may share one
     */
    private static function feedIdsOf(array $subscriptions): array
    {
        $feedIds = [];
        foreach ($subscriptions as $subscription) {
            $feedIds[$subscription->getFeed()->requireId()] = true;
        }

        return array_keys($feedIds);
    }

    private static function isSavedFeed(Subscription $subscription): bool
    {
        return SourceFormat::SAVED === $subscription->getFeed()->getSourceFormat();
    }

    /**
     * A candidate whose URL is the source itself (scraped page, REST endpoint):
     * store it verbatim and skip re-discovery.
     *
     * @param 'scraped'|'wp-json' $format
     * @param list<Tag>           $tags
     */
    private function subscribeVerbatim(
        User $user,
        string $url,
        string $format,
        array $tags,
        ?string $initialTitle = null,
    ): SubscribeOutcomeModel {
        return SubscribeOutcomeModel::subscribed($this->creator->create($user, $url, $format, $tags, $initialTitle));
    }
}
