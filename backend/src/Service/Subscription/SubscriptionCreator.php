<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Exception\AlreadySubscribedException;
use App\Exception\SubscriptionLimitReachedException;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionTagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The row mechanics of subscribing one feed: the per-user cap, the shared Feed
 * row, the duplicate check, the tag positions.
 *
 * BulkSubscriber is the batch sibling and keeps its own copy of these rules
 * because it defers every flush to the end of the import — a difference worth
 * collapsing one day, and the reason neither class may claim to be the only
 * place a subscription comes into being.
 */
final readonly class SubscriptionCreator
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private FeedRepository $feeds,
        private SubscriptionTagRepository $subscriptionTags,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private SubscriptionLimitResolver $subscriptionLimits,
    ) {
    }

    /**
     * Both single-feed paths — discovery-confirmed and the scraped shortcut —
     * come through here, so the cap, the shared-feed lookup and the duplicate
     * check cannot diverge between them.
     *
     * @param 'xml'|'scraped'|'wp-json' $sourceFormat
     * @param list<Tag>                 $tags
     */
    public function create(User $user, string $feedUrl, string $sourceFormat, array $tags): Subscription
    {
        $userId = (int) $user->getId();
        $limit = $this->subscriptionLimits->resolve($user);
        if ($this->subscriptions->countForUser($userId) >= $limit) {
            throw new SubscriptionLimitReachedException($limit);
        }

        $feed = $this->feeds->findOneBy(['url' => $feedUrl]);
        if (null === $feed) {
            // New shared feed: nextFetchAt null => due immediately; the first
            // refresh fills in title/entries. Metadata is the refresh pipeline's
            // job, not the subscribe path's.
            $feed = new Feed($feedUrl);
            $feed->setSourceFormat($sourceFormat);
            $this->em->persist($feed);
            $this->em->flush(); // assign an id so the duplicate check is meaningful
        } elseif (SourceFormat::XML === $sourceFormat && SourceFormat::SCRAPED === $feed->getSourceFormat()) {
            // One-way heal for a poisoned shared row: 'xml' arrivals come from
            // discovery PARSING the URL as a real feed document — a stronger
            // fact than the 'scraped' assertion of whoever created the row
            // (who may have posted format 'scraped' for an XML feed, leaving
            // every refresh to run the HTML extractor over RSS and error out).
            // Never the reverse: a 'scraped' arrival is user-asserted and must
            // not downgrade a format discovery or the creator established.
            $feed->setSourceFormat(SourceFormat::XML);
            // Persist the heal in its own step, BEFORE the duplicate check can
            // throw. An existing victim re-adding the feed to fix its format is
            // the natural repair path; without this flush that check aborts the
            // unit of work and the heal is rolled back, so the fix does nothing.
            $this->em->flush();
        }

        if ($this->subscriptions->existsForUserAndFeed($userId, (int) $feed->getId())) {
            throw new AlreadySubscribedException();
        }

        $subscription = new Subscription($user, $feed, $this->clock->now());
        $subscription->setPosition($this->subscriptions->nextPositionForUser($userId));
        $this->attachTags($subscription, $tags);
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    /**
     * Attach each tag at the end of its own list (one past the tag's current
     * max), so a feed added to a tag never floats above feeds already in it.
     * The join rows cascade-persist with the subscription.
     *
     * @param list<Tag> $tags
     */
    private function attachTags(Subscription $subscription, array $tags): void
    {
        foreach ($tags as $tag) {
            $subscription->addTag($tag, $this->subscriptionTags->nextPositionForTag($tag));
        }
    }
}
