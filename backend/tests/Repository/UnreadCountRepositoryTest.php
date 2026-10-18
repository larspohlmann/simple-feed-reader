<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\UnreadCountRepository;
use App\Tests\DbTestCase;

final class UnreadCountRepositoryTest extends DbTestCase
{
    private const string WHEN = '2026-07-20T00:00:00Z';

    public function testCountsUnreadPerSubscriptionRespectingWatermarkAndState(): void
    {
        $user = $this->user();
        [$subscription, $feed] = $this->subscribedFeed($user, 'https://example.com/f.xml');
        $subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));

        foreach (
            [
                ['a', '2026-07-05'],
                ['b', '2026-07-20'],
                ['c', '2026-07-21'],
                ['d', '2026-07-22'],
            ] as [$guid, $publishedDay]
        ) {
            $publishedAt = new \DateTimeImmutable($publishedDay . 'T00:00:00Z');
            $entry = new Entry($feed, $guid, null, $guid, new \DateTimeImmutable('2026-07-01T00:00:00Z'), $publishedAt);
            $entry->setPublishedAt($publishedAt);
            $this->entityManager->persist($entry);
            if ($guid === 'c') {
                $entryState = new EntryState($user, $entry);
                $entryState->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
                $this->entityManager->persist($entryState);
            }
        }
        $this->entityManager->flush();

        $counts = $this->repository()->perSubscription($user->requireId());

        self::assertSame(2, $counts[$subscription->requireId()] ?? 0);
    }

    public function testSubscriptionWithNoUnreadIsAbsentFromMap(): void
    {
        $user = $this->user();
        [$subscription] = $this->subscribedFeed($user, 'https://example.com/empty.xml');

        $counts = $this->repository()->perSubscription($user->requireId());

        self::assertArrayNotHasKey($subscription->requireId(), $counts);
    }

    public function testReadingOneCopyLeavesItsSiblingUnread(): void
    {
        $user = $this->user();
        [$subscriptionA, $feedA] = $this->subscribedFeed($user, 'https://example.com/a.xml');
        [$subscriptionB, $feedB] = $this->subscribedFeed($user, 'https://example.com/b.xml');
        $readCopy = $this->unreadEntry($feedA, 'read-copy', 'shared-hash');
        $this->unreadEntry($feedB, 'unread-copy', 'shared-hash');
        $state = new EntryState($user, $readCopy);
        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $counts = $this->repository()->perSubscription($user->requireId());

        self::assertSame(1, $counts[$subscriptionB->requireId()] ?? 0);
        self::assertSame(0, $counts[$subscriptionA->requireId()] ?? 0);
    }

    public function testEachSubscriptionCountsItsOwnCopyOfACrossFeedDuplicate(): void
    {
        $user = $this->user();
        [$home, $homeFeed] = $this->subscribedFeed($user, 'social:bluesky:1:home');
        [$custom, $customFeed] = $this->subscribedFeed($user, 'social:bluesky:1:feed:x');
        $this->unreadEntry($homeFeed, 'home-copy', 'shared-hash');
        $this->unreadEntry($customFeed, 'custom-copy', 'shared-hash');

        $counts = $this->repository()->perSubscription($user->requireId());

        self::assertSame(1, $counts[$home->requireId()] ?? 0);
        self::assertSame(1, $counts[$custom->requireId()] ?? 0);
    }

    public function testAllItemsCountsEveryCopyOfACrossFeedDuplicate(): void
    {
        $user = $this->user();
        [, $feedA] = $this->subscribedFeed($user, 'https://example.com/a.xml');
        [, $feedB] = $this->subscribedFeed($user, 'https://example.com/b.xml');
        $this->unreadEntry($feedA, 'a', 'shared-hash');
        $this->unreadEntry($feedB, 'b', 'shared-hash');
        $this->unreadEntry($feedB, 'unique', null);

        self::assertSame(3, $this->repository()->allItems($user->requireId()));
    }

    public function testAllItemsSkipsFeedsExcludedFromAllItems(): void
    {
        $user = $this->user();
        [$excluded, $excludedFeed] = $this->subscribedFeed($user, 'https://example.com/excluded.xml');
        $excluded->setIncludeInAllItems(false);
        [, $includedFeed] = $this->subscribedFeed($user, 'https://example.com/included.xml');
        $this->unreadEntry($excludedFeed, 'excluded-copy', 'shared-hash');
        $this->unreadEntry($includedFeed, 'included-copy', 'shared-hash');

        self::assertSame(1, $this->repository()->allItems($user->requireId()));
    }

    public function testATagCountsEveryCopyItHolds(): void
    {
        $user = $this->user();
        $tag = $this->tag($user, 'news');
        [$first, $feedA] = $this->subscribedFeed($user, 'https://example.com/a.xml');
        [$second, $feedB] = $this->subscribedFeed($user, 'https://example.com/b.xml');
        $first->addTag($tag);
        $second->addTag($tag);
        $this->unreadEntry($feedA, 'a', 'shared-hash');
        $this->unreadEntry($feedB, 'b', 'shared-hash');

        $counts = $this->repository()->perTag($user->requireId());

        self::assertSame(2, $counts[$tag->requireId()] ?? 0);
    }

    public function testATagCountsOnlyItsTaggedFeeds(): void
    {
        $user = $this->user();
        $tag = $this->tag($user, 'news');
        [, $untaggedFeed] = $this->subscribedFeed($user, 'https://example.com/untagged.xml');
        [$tagged, $taggedFeed] = $this->subscribedFeed($user, 'https://example.com/tagged.xml');
        $tagged->addTag($tag);
        $this->unreadEntry($untaggedFeed, 'untagged-copy', 'shared-hash');
        $this->unreadEntry($taggedFeed, 'tagged-copy', 'shared-hash');

        $counts = $this->repository()->perTag($user->requireId());

        self::assertSame(1, $counts[$tag->requireId()] ?? 0);
    }

    public function testASubscriptionCountsInEachOfItsTags(): void
    {
        $user = $this->user();
        $news = $this->tag($user, 'news');
        $tech = $this->tag($user, 'tech');
        [$subscription, $feed] = $this->subscribedFeed($user, 'https://example.com/a.xml');
        $subscription->addTag($news);
        $subscription->addTag($tech);
        $this->unreadEntry($feed, 'a', null);

        $counts = $this->repository()->perTag($user->requireId());

        self::assertSame([$news->requireId() => 1, $tech->requireId() => 1], $counts);
    }

    public function testANetworkCountsEveryCopyAcrossItsFeeds(): void
    {
        $user = $this->user();
        [$home, $homeFeed] = $this->subscribedFeed($user, 'social:bluesky:1:home', SourceFormat::BLUESKY_API);
        [$custom, $customFeed] = $this->subscribedFeed($user, 'social:bluesky:1:feed:x', SourceFormat::BLUESKY_API);
        foreach (['one', 'two', 'three', 'four'] as $post) {
            $this->unreadEntry($homeFeed, 'home-' . $post, 'hash-' . $post);
            $this->unreadEntry($customFeed, 'custom-' . $post, 'hash-' . $post);
        }
        $this->unreadEntry($customFeed, 'custom-extra', 'hash-extra');

        $repository = $this->repository();
        $perSubscription = $repository->perSubscription($user->requireId());

        self::assertSame(9, $repository->perNetwork($user->requireId())[SocialNetwork::Bluesky->value] ?? 0);
        self::assertSame(4, $perSubscription[$home->requireId()] ?? 0);
        self::assertSame(5, $perSubscription[$custom->requireId()] ?? 0);
    }

    public function testAFeedOnNoNetworkIsInNoNetworkCount(): void
    {
        $user = $this->user();
        [, $blueskyFeed] = $this->subscribedFeed($user, 'social:bluesky:1:home', SourceFormat::BLUESKY_API);
        [, $plainFeed] = $this->subscribedFeed($user, 'https://example.com/plain.xml');
        $this->unreadEntry($blueskyFeed, 'post', null);
        $this->unreadEntry($plainFeed, 'article', null);

        self::assertSame([SocialNetwork::Bluesky->value => 1], $this->repository()->perNetwork($user->requireId()));
    }

    public function testNetworkProfilesCountOnlyProfileFeeds(): void
    {
        $user = $this->user();
        [, $homeFeed] = $this->subscribedFeed($user, 'social:bluesky:1:home', SourceFormat::BLUESKY_API);
        [, $profileFeed] = $this->subscribedFeed($user, 'https://bsky.app/profile/alice.bsky.social/rss');
        $this->unreadEntry($homeFeed, 'home-1', null);
        $this->unreadEntry($homeFeed, 'home-2', null);
        $this->unreadEntry($profileFeed, 'profile-1', null);

        $repository = $this->repository();

        self::assertSame(1, $repository->perNetworkProfiles($user->requireId())[SocialNetwork::Bluesky->value] ?? 0);
        self::assertSame(3, $repository->perNetwork($user->requireId())[SocialNetwork::Bluesky->value] ?? 0);
    }

    public function testEachNetworkCountsItsOwnCopy(): void
    {
        $user = $this->user();
        [, $blueskyFeed] = $this->subscribedFeed($user, 'social:bluesky:1:home', SourceFormat::BLUESKY_API);
        [, $mastodonFeed] = $this->subscribedFeed($user, 'social:mastodon:2:home', SourceFormat::MASTODON_API);
        $this->unreadEntry($blueskyFeed, 'bluesky-copy', 'shared-hash');
        $this->unreadEntry($mastodonFeed, 'mastodon-copy', 'shared-hash');

        $counts = $this->repository()->perNetwork($user->requireId());

        self::assertSame(1, $counts[SocialNetwork::Bluesky->value] ?? 0);
        self::assertSame(1, $counts[SocialNetwork::Mastodon->value] ?? 0);
    }

    private function repository(): UnreadCountRepository
    {
        $repository = self::getContainer()->get(UnreadCountRepository::class);
        self::assertInstanceOf(UnreadCountRepository::class, $repository);

        return $repository;
    }

    private function user(): User
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function tag(User $user, string $name): Tag
    {
        $tag = new Tag($user, $name);
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    /** @return array{0: Subscription, 1: Feed} */
    private function subscribedFeed(User $user, string $url, string $sourceFormat = SourceFormat::XML): array
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return [$subscription, $feed];
    }

    private function unreadEntry(Feed $feed, string $guid, ?string $urlHash): Entry
    {
        $publishedAt = new \DateTimeImmutable(self::WHEN);
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.com/' . $guid,
            $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $publishedAt,
            $urlHash,
        );
        $entry->setPublishedAt($publishedAt);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
