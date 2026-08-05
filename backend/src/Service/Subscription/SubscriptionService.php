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
     * @param list<Tag> $tags the user-owned tags to attach to a newly created
     *                        subscription; ignored when the outcome is a
     *                        candidate list rather than a subscription
     */
    public function subscribe(User $user, string $url, ?string $format = null, array $tags = []): SubscribeOutcome
    {
        // A 'scraped' subscribe re-posts a candidate URL discovery itself just
        // produced: the page IS the feed. Running discovery again would
        // re-fetch and re-extract for nothing — or, worse, fail this time and
        // block a subscribe the user was already offered. The URL is stored
        // VERBATIM (no finalUrl canonicalization, unlike the discovery path):
        // candidates round-trip the canonical URL discovery just emitted, and
        // a hand-typed variant merely becomes its own row that counts against
        // this user's cap and converges via applyPermanentRedirect on refresh.
        if (SourceFormat::SCRAPED === $format) {
            // Discovery never offers a scraped candidate to an account with the
            // preference off, so a request that reaches here with it off is a
            // hand-made one — refuse it rather than let this shortcut become
            // the bypass discovery's own gate (Task 5) cannot see.
            $this->scrapeFallbackPolicy->assertMayScrape($user);

            return SubscribeOutcome::subscribed(
                $this->creator->create($user, $url, SourceFormat::SCRAPED, $tags),
            );
        }

        $result = $this->discovery->discover($url, $this->scrapeFallbackPolicy->forUser($user));

        if (!$result->isDirectFeed) {
            return SubscribeOutcome::candidates($result->candidates, $result->scrapeFailureReason);
        }

        $subscription = $this->creator->create($user, (string) $result->feedUrl, SourceFormat::XML, $tags);
        if (null !== $result->document) {
            // Discovery read the feed to confirm it was one. Storing that
            // document here is what makes a new subscription arrive with its
            // entries, instead of empty until a refresh re-fetches the URL.
            $this->firstFetch->record($subscription->getFeed(), $result->document);
        }

        return SubscribeOutcome::subscribed($subscription);
    }
}
