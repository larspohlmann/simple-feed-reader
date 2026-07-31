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
 * Subscribes a batch of feeds in one unit of work, WITHOUT fetching or
 * discovering anything. Shared by OPML import and the onboarding catalog so the
 * cap, the duplicate checks and the position arithmetic exist exactly once.
 *
 * Nothing flushes until the end, so a repository lookup cannot see rows created
 * earlier in THIS batch. Two batch-local maps stand in for that, guarding the
 * unique constraints the deferred flush would otherwise trip:
 *  - $tagCache (uniq_tag_user_name): several items naming one tag reuse a single
 *    Tag row instead of persisting duplicates.
 *  - $seen (uniq_subscription_user_feed): a URL listed twice subscribes once and
 *    counts as alreadySubscribed.
 */
final readonly class BulkSubscriber
{
    private const int MAX_TAG_NAME = 100;

    /**
     * Feed.url is VARCHAR(750); a longer URL would pass a naive scheme/host check
     * yet blow up the deferred flush with "data too long" on MySQL (strict mode),
     * losing the whole batch. Bound it here so an over-long URL is merely counted
     * invalid and the rest still lands.
     */
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
        $userId = (int) $user->getId();
        $state = new BulkSubscribeState(
            existing: $this->subscriptions->countForUser($userId),
            nextSubscriptionPosition: $this->subscriptions->nextPositionForUser($userId),
            nextTagPosition: $this->tags->nextPositionForUser($userId),
        );
        $result = new BulkSubscribeResult();

        foreach ($items as $item) {
            $result = $this->subscribeOne($user, $item, $state, $result);
        }

        $this->em->flush();

        return $result;
    }

    private function subscribeOne(
        User $user,
        BulkSubscribeItem $item,
        BulkSubscribeState $state,
        BulkSubscribeResult $result,
    ): BulkSubscribeResult {
        $url = $item->feedUrl;

        if (!$this->isSubscribableUrl($url)) {
            return $result->with(invalid: 1);
        }
        if (isset($state->seen[$url])) {
            return $result->with(alreadySubscribed: 1);
        }

        // Look up but do NOT create yet: an over-limit batch must not leave orphan
        // Feed rows behind for feeds it never subscribes to.
        $feed = $this->feeds->findOneBy(['url' => $url]);
        if (null !== $feed && $this->subscriptions->existsForUserAndFeed((int) $user->getId(), (int) $feed->getId())) {
            return $result->with(alreadySubscribed: 1);
        }
        if ($state->existing >= $this->subscriptionLimits->resolve($user)) {
            return $result->with(skippedOverLimit: 1);
        }

        if (null === $feed) {
            $feed = new Feed($url);
            $feed->setSourceFormat($item->sourceFormat);
            // Seeded from the catalog so the sidebar reads properly before the
            // first fetch. Only on creation: a shared row another user already
            // has is not ours to retitle.
            $feed->setTitle($item->feedTitle);
            $feed->setNextFetchAt($this->clock->now()); // due now → next refresh populates it
            $this->em->persist($feed);
        }

        $subscription = new Subscription($user, $feed, $this->clock->now());
        $subscription->setPosition($state->nextSubscriptionPosition++);
        $this->em->persist($subscription);

        $created = $this->attachTag($user, $subscription, $item, $state);

        $state->seen[$url] = true;
        ++$state->existing;

        return $result->with(imported: 1, tagsCreated: $created);
    }

    /**
     * @return list<Tag> the tag if this call brought it into being, else empty
     */
    private function attachTag(
        User $user,
        Subscription $subscription,
        BulkSubscribeItem $item,
        BulkSubscribeState $state,
    ): array {
        if (null === $item->tagName) {
            return [];
        }

        $name = mb_substr($item->tagName, 0, self::MAX_TAG_NAME);
        $key = mb_strtolower($name);

        $created = [];
        $tag = $state->tagCache[$key] ?? $this->tags->findOneByNameForUser((int) $user->getId(), $name);
        if (null === $tag) {
            $tag = new Tag($user, $name);
            $tag->setColor($item->tagStyle?->color);
            $tag->setIcon($item->tagStyle?->icon);
            $tag->setPosition($state->nextTagPosition++);
            $this->em->persist($tag);
            $created[] = $tag;
        }
        $state->tagCache[$key] = $tag;

        // Nothing is flushed yet, so DB MAX(position) cannot see joins made in
        // this batch: a tag created here starts at 0, an existing one appends
        // past its committed feeds.
        $oid = spl_object_id($tag);
        $state->nextFeedPositionInTag[$oid] ??= null === $tag->getId()
            ? 0
            : $this->subscriptionTags->nextPositionForTag($tag);
        $subscription->addTag($tag, $state->nextFeedPositionInTag[$oid]++);

        return $created;
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
