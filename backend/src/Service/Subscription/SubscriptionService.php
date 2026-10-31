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
use App\Service\Subscription\Model\SubscribeRequestModel;
use App\Service\Subscription\SubscribeRoute\SubscribeRouteInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class SubscriptionService
{
    public const int MAX_SUBSCRIPTIONS_PER_USER = 500;

    /**
     * Bounds one bulk request's payload, not the subscription cap: an admin may raise an account's cap above
     * MAX_SUBSCRIPTIONS_PER_USER, which a validation attribute cannot read. OwnedSubscriptions still checks every id.
     */
    public const int MAX_BULK_REQUEST_IDS = 5000;

    /** @param iterable<SubscribeRouteInterface> $subscribeRoutes */
    public function __construct(
        private FeedDiscoveryInterface $discovery,
        private SubscriptionCreator $creator,
        private ScrapeFallbackPolicy $scrapeFallbackPolicy,
        private FirstFetchRecorder $firstFetch,
        private OrphanedFeedReclaimer $orphanedFeeds,
        private SavedPostRescue $savedPostRescue,
        private EntityManagerInterface $entityManager,
        #[AutowireIterator('app.subscribe_route')]
        private iterable $subscribeRoutes,
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

        [$savedFeeds, $others] = self::savedFeedsAndOthers($subscriptions);
        $this->removeRescuing($savedFeeds, []);
        $copyIds = $this->removeRescuing($others, $others);
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
        if (SourceFormat::isVerbatim($format)) {
            return $this->subscribeVerbatim($user, $url, $format, $tags, $initialTitle);
        }

        $routed = $this->routed(new SubscribeRequestModel($user, $url, $tags));
        if (null !== $routed) {
            return $routed;
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

    private function routed(SubscribeRequestModel $request): ?SubscribeOutcomeModel
    {
        foreach ($this->subscribeRoutes as $route) {
            $outcome = $route->subscribe($request);
            if (null !== $outcome) {
                return $outcome;
            }
        }

        return null;
    }

    /**
     * @param list<Subscription> $subscriptions
     * @param list<Subscription> $rescued       the ones whose favourites and kept posts are saved before they go
     *
     * @return list<int> the saved copies the rescue created
     */
    private function removeRescuing(array $subscriptions, array $rescued): array
    {
        if ([] === $subscriptions) {
            return [];
        }
        $feedIds = self::feedIdsOf($subscriptions);
        $this->entityManager->beginTransaction();
        try {
            $result = $this->savedPostRescue->rescueFrom($rescued);
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

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return array{list<Subscription>, list<Subscription>} the saved feeds, then the others
     */
    private static function savedFeedsAndOthers(array $subscriptions): array
    {
        $savedFeeds = [];
        $others = [];
        foreach ($subscriptions as $subscription) {
            if (SourceFormat::isSaved($subscription->getFeed()->getSourceFormat())) {
                $savedFeeds[] = $subscription;
                continue;
            }
            $others[] = $subscription;
        }

        return [$savedFeeds, $others];
    }

    /**
     * A candidate whose URL is the source itself is stored as offered: discovery run again could fail this time and
     * block the subscribe it just offered.
     *
     * @param value-of<SourceFormat::VERBATIM> $format
     * @param list<Tag>                        $tags
     */
    private function subscribeVerbatim(
        User $user,
        string $url,
        string $format,
        array $tags,
        ?string $initialTitle,
    ): SubscribeOutcomeModel {
        if (SourceFormat::SCRAPED === $format) {
            // This shortcut skips discovery's scrape gate, so a hand-made request must meet the preference here.
            $this->scrapeFallbackPolicy->assertMayScrape($user);
        }

        return SubscribeOutcomeModel::subscribed($this->creator->create(
            $user,
            $url,
            $format,
            $tags,
            SourceFormat::SCRAPED === $format ? null : $initialTitle,
        ));
    }
}
