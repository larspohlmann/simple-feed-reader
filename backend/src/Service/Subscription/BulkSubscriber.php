<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Subscribes a batch of feeds in one unit of work without fetching anything, for OPML import and the onboarding
 * catalog alike. Nothing flushes until the end, so a BulkSubscribeBatch stands in for the rows this batch created.
 */
final readonly class BulkSubscriber
{
    private const int MAX_TAG_NAME = 100;

    /** Feed.url is VARCHAR(750): a longer URL counts as invalid instead of failing the whole batch's flush. */
    private const int MAX_FEED_URL = 750;

    public function __construct(
        private EntityManagerInterface $em,
        private FeedRepository $feeds,
        private SubscriptionRepository $subscriptions,
        private SubscriptionTagRepository $subscriptionTags,
        private TagRepository $tags,
        private ClockInterface $clock,
        private SubscriptionLimitResolver $subscriptionLimits,
    ) {
    }

    /**
     * @param iterable<BulkSubscribeItem> $items
     */
    public function subscribeAll(User $user, iterable $items): BulkSubscribeResult
    {
        $batch = $this->open($user);
        foreach ($items as $item) {
            $this->subscribeOne($batch, $item);
        }

        $this->em->flush();

        return $batch->result();
    }

    private function open(User $user): BulkSubscribeBatch
    {
        $userId = $user->requireId();

        return new BulkSubscribeBatch(
            $user,
            $this->subscriptionLimits->resolve($user) - $this->subscriptions->countForUser($userId),
            new BulkSubscribePositions(
                $this->subscriptions->nextPositionForUser($userId),
                $this->tags->nextPositionForUser($userId),
            ),
        );
    }

    private function subscribeOne(BulkSubscribeBatch $batch, BulkSubscribeItem $item): void
    {
        $url = $item->feedUrl;
        if (!$this->isSubscribableUrl($url)) {
            $batch->countInvalid();

            return;
        }
        if ($batch->hasSubscribed($url)) {
            $batch->countAlreadySubscribed();

            return;
        }

        // Looked up, not created yet: an item over the cap must not leave an orphan Feed row behind.
        $feed = $this->feeds->findOneBy(['url' => $url]);
        if ($this->isSubscribedTo($batch->user, $feed)) {
            $batch->countAlreadySubscribed();

            return;
        }
        if ($batch->isFull()) {
            $batch->countOverLimit();

            return;
        }

        $subscription = $this->persistSubscription($batch, $feed ?? $this->persistNewFeed($item));
        $batch->recordSubscribed($url, $subscription, $this->attachTag($batch, $subscription, $item));
    }

    private function isSubscribedTo(User $user, ?Feed $feed): bool
    {
        return null !== $feed && $this->subscriptions->existsForUserAndFeed($user->requireId(), $feed->requireId());
    }

    private function persistNewFeed(BulkSubscribeItem $item): Feed
    {
        $feed = new Feed($item->feedUrl);
        $feed->setSourceFormat($item->sourceFormat);
        // Seeded for the sidebar before the first fetch; only on creation, since a shared row is not ours to retitle.
        $feed->setTitle($item->feedTitle);
        $feed->scheduleNextFetchAt($this->clock->now());
        $this->em->persist($feed);

        return $feed;
    }

    private function persistSubscription(BulkSubscribeBatch $batch, Feed $feed): Subscription
    {
        $subscription = new Subscription($batch->user, $feed, $this->clock->now());
        $subscription->setPosition($batch->positions->takeSubscriptionPosition());
        $this->em->persist($subscription);

        return $subscription;
    }

    /**
     * @return list<Tag> the tag if this call brought it into being, else empty
     */
    private function attachTag(BulkSubscribeBatch $batch, Subscription $subscription, BulkSubscribeItem $item): array
    {
        if (null === $item->tagName) {
            return [];
        }

        $name = mb_substr($item->tagName, 0, self::MAX_TAG_NAME);
        $existing = $batch->tagNamed($name) ?? $this->tags->findOneByNameForUser($batch->user->requireId(), $name);
        $tag = $existing ?? $this->persistNewTag($batch, $name, $item->tagStyle);
        $batch->rememberTag($name, $tag);
        $this->joinTag($batch->positions, $subscription, $tag);

        return null === $existing ? [$tag] : [];
    }

    private function persistNewTag(BulkSubscribeBatch $batch, string $name, ?TagStyle $style): Tag
    {
        $tag = new Tag($batch->user, $name);
        $tag->setColor($style?->color);
        $tag->setIcon($style?->icon);
        $tag->setPosition($batch->positions->takeTagPosition());
        $this->em->persist($tag);

        return $tag;
    }

    private function joinTag(BulkSubscribePositions $positions, Subscription $subscription, Tag $tag): void
    {
        // A tag created in this batch starts at 0; an existing one appends past its committed feeds.
        $subscription->addTag($tag, $positions->takeFeedPositionIn(
            $tag,
            fn (): int => null === $tag->getId() ? 0 : $this->subscriptionTags->nextPositionForTag($tag),
        ));
    }

    private function isSubscribableUrl(string $url): bool
    {
        if (mb_strlen($url) > self::MAX_FEED_URL) {
            return false;
        }
        $scheme = parse_url($url, \PHP_URL_SCHEME);
        $host = parse_url($url, \PHP_URL_HOST);

        return \in_array($scheme, ['http', 'https'], true) && \is_string($host) && '' !== $host;
    }
}
