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
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Flushes the removal before reclaiming the feed, so the DELETE's no-subscriber guard sees the row gone;
     * reclaim() does nothing while anybody else subscribes.
     */
    public function unsubscribe(Subscription $subscription): void
    {
        $feedId = $subscription->getFeed()->requireId();

        $this->entityManager->remove($subscription);
        $this->entityManager->flush();

        $this->orphanedFeeds->reclaim($feedId);
    }

    /**
     * One flush for the whole selection, then one reclaim per distinct feed: two removed subscriptions may share one.
     *
     * @param list<Subscription> $subscriptions
     */
    public function unsubscribeAll(array $subscriptions): int
    {
        if ([] === $subscriptions) {
            return 0;
        }

        $feedIds = [];
        foreach ($subscriptions as $subscription) {
            $feedIds[$subscription->getFeed()->requireId()] = true;
            $this->entityManager->remove($subscription);
        }
        $this->entityManager->flush();

        foreach (array_keys($feedIds) as $feedId) {
            $this->orphanedFeeds->reclaim($feedId);
        }

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
