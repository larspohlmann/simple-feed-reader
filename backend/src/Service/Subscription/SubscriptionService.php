<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Service\Discovery\FeedDiscoveryInterface;
use App\Service\Discovery\ScrapeFallbackPolicy;
use App\Service\OrphanedFeedReclaimer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SubscriptionService
{
    public const int MAX_SUBSCRIPTIONS_PER_USER = 500;

    /**
     * A hard technical ceiling on how many ids ONE bulk request may name — it
     * bounds payload/array size, nothing else. It is deliberately NOT tied to
     * MAX_SUBSCRIPTIONS_PER_USER: SubscriptionLimitResolver lets an admin raise
     * a single account's real cap above the global default, and a validation
     * attribute cannot read the current user to match it exactly. Every id in
     * the request is still checked for ownership downstream (OwnedSubscriptions),
     * so this ceiling only needs to be generous, not exact.
     */
    public const int MAX_BULK_REQUEST_IDS = 5000;

    public function __construct(
        private FeedDiscoveryInterface $discovery,
        private SubscriptionCreator $creator,
        private ScrapeFallbackPolicy $scrapeFallbackPolicy,
        private FirstFetchRecorder $firstFetch,
        private OrphanedFeedReclaimer $orphanedFeeds,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Removes one subscription and reclaims the feed if that was the last one.
     * The removal is flushed before the reclaim so the DELETE's no-subscriber
     * guard sees the row is gone; reclaim() is a no-op when anybody else still
     * subscribes.
     */
    public function unsubscribe(Subscription $subscription): void
    {
        $feedId = (int) $subscription->getFeed()->getId();

        $this->em->remove($subscription);
        $this->em->flush();

        $this->orphanedFeeds->reclaim($feedId);
    }

    /**
     * Removes many subscriptions in one transaction, then reclaims each feed
     * that lost its last subscriber.
     *
     * The single flush is the point: unsubscribe() flushes and reclaims per
     * call, which a 176-feed selection would turn into 176 transactions and 176
     * orphan sweeps. Reclaiming per DISTINCT feed after the flush also matters
     * — two of the removed subscriptions can point at the same feed, and
     * reclaim() must not be asked about it twice.
     *
     * @param list<Subscription> $subscriptions
     *
     * @return int how many subscriptions were removed
     */
    public function unsubscribeAll(array $subscriptions): int
    {
        if ([] === $subscriptions) {
            return 0;
        }

        $feedIds = [];
        foreach ($subscriptions as $subscription) {
            $feedIds[(int) $subscription->getFeed()->getId()] = true;
            $this->em->remove($subscription);
        }
        $this->em->flush();

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
    ): SubscribeOutcome {
        // A 'scraped' or 'wp-json' subscribe re-posts a candidate URL discovery
        // itself just produced: the URL IS the source. Running discovery again
        // would re-fetch for nothing — or fail this time and block a subscribe
        // the user was already offered. Both are stored VERBATIM.
        if (SourceFormat::SCRAPED === $format) {
            // Discovery never offers a scraped candidate to an account with the
            // preference off, so a request that reaches here with it off is a
            // hand-made one — refuse it rather than let this shortcut become
            // the bypass discovery's own gate cannot see.
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
            return SubscribeOutcome::candidates($result->candidates, $result->scrapeFailureReason);
        }

        $subscription = $this->creator->create($user, $discovered->url, SourceFormat::XML, $tags);
        $unread = $this->firstFetch->record($subscription->getFeed(), $discovered);

        return SubscribeOutcome::subscribed($subscription, $unread);
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
    ): SubscribeOutcome {
        return SubscribeOutcome::subscribed($this->creator->create($user, $url, $format, $tags, $initialTitle));
    }
}
