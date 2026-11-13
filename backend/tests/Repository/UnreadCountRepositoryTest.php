<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\NetworkExclusion;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\FeedGroup;
use App\Enum\NetworkExclusionSurface;
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

    public function testEveryAncestorCountsAGrandchildsEntryOnce(): void
    {
        $user = $this->user();
        $reading = $this->tag($user, 'reading');
        $longReads = $this->childTag($user, 'long reads', $reading);
        $essays = $this->childTag($user, 'essays', $longReads);
        [$deep, $deepFeed] = $this->subscribedFeed($user, 'https://example.com/deep.xml');
        $deep->addTag($essays);
        $deep->addTag($longReads);
        [$top, $topFeed] = $this->subscribedFeed($user, 'https://example.com/top.xml');
        $top->addTag($reading);
        [$essayOnly, $essayOnlyFeed] = $this->subscribedFeed($user, 'https://example.com/essay-only.xml');
        $essayOnly->addTag($essays);
        $this->unreadEntry($deepFeed, 'deep-entry', null);
        $this->unreadEntry($topFeed, 'top-entry', null);
        $this->unreadEntry($essayOnlyFeed, 'essay-only-entry', null);

        $counts = $this->perTag($user);
        ksort($counts);

        self::assertSame(
            [$reading->requireId() => 3, $longReads->requireId() => 2, $essays->requireId() => 2],
            $counts,
        );
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

        self::assertSame(3, $this->allItems($user));
    }

    public function testAllItemsSkipsFeedsExcludedFromAllItems(): void
    {
        $user = $this->user();
        [$excluded, $excludedFeed] = $this->subscribedFeed($user, 'https://example.com/excluded.xml');
        $excluded->setIncludeInAllItems(false);
        [, $includedFeed] = $this->subscribedFeed($user, 'https://example.com/included.xml');
        $this->unreadEntry($excludedFeed, 'excluded-copy', 'shared-hash');
        $this->unreadEntry($includedFeed, 'included-copy', 'shared-hash');

        self::assertSame(1, $this->allItems($user));
    }

    public function testAllItemsSkipsANetworkSwitchedOutOfAllItems(): void
    {
        $user = $this->user();
        [, $blueskyFeed] = $this->subscribedFeed($user, 'https://bsky.app/profile/alice.bsky.social/rss');
        [, $blogFeed] = $this->subscribedFeed($user, 'https://example.com/blog.xml');
        $this->unreadEntry($blueskyFeed, 'post', null);
        $this->unreadEntry($blogFeed, 'article', null);
        $this->excludeBlueskyFrom($user, NetworkExclusionSurface::AllItems);

        self::assertSame(1, $this->allItems($user));
        self::assertSame(1, $this->perNetwork($user)[SocialNetwork::Bluesky->value] ?? 0);
    }

    public function testAllItemsSkipsFeedsATagSwitchesOutOfAllItems(): void
    {
        $user = $this->user();
        $switchedOff = $this->tag($user, 'off');
        $switchedOff->setIncludeInAllItems(false);
        $below = $this->childTag($user, 'below', $switchedOff);
        $included = $this->tag($user, 'on');
        [$offTagged, $offFeed] = $this->subscribedFeed($user, 'https://example.com/off.xml');
        $offTagged->addTag($switchedOff);
        $offTagged->addTag($included);
        [$belowTagged, $belowFeed] = $this->subscribedFeed($user, 'https://example.com/below.xml');
        $belowTagged->addTag($below);
        [$onTagged, $onFeed] = $this->subscribedFeed($user, 'https://example.com/on.xml');
        $onTagged->addTag($included);
        [, $untaggedFeed] = $this->subscribedFeed($user, 'https://example.com/untagged.xml');
        $this->unreadEntry($offFeed, 'off', null);
        $this->unreadEntry($belowFeed, 'below', null);
        $this->unreadEntry($onFeed, 'on', null);
        $this->unreadEntry($untaggedFeed, 'untagged', null);

        self::assertSame(2, $this->allItems($user));
        self::assertSame(2, $this->perTag($user)[$switchedOff->requireId()] ?? 0);
    }

    public function testAForYouSwitchLeavesAllItemsCounting(): void
    {
        $user = $this->user();
        [, $blueskyFeed] = $this->subscribedFeed($user, 'https://bsky.app/profile/alice.bsky.social/rss');
        $this->unreadEntry($blueskyFeed, 'post', null);
        $this->excludeBlueskyFrom($user, NetworkExclusionSurface::ForYou);

        self::assertSame(1, $this->allItems($user));
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

        $counts = $this->perTag($user);

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

        $counts = $this->perTag($user);

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

        $counts = $this->perTag($user);

        self::assertSame([$news->requireId() => 1, $tech->requireId() => 1], $counts);
    }

    public function testAParentCountsItsOwnAndItsChildrensFeedsEachEntryOnce(): void
    {
        $user = $this->user();
        $reading = $this->tag($user, 'reading');
        $longReads = $this->childTag($user, 'long reads', $reading);
        [$parentOnly, $parentFeed] = $this->subscribedFeed($user, 'https://example.com/parent.xml');
        $parentOnly->addTag($reading);
        [$childOnly, $childFeed] = $this->subscribedFeed($user, 'https://example.com/child.xml');
        $childOnly->addTag($longReads);
        [$both, $bothFeed] = $this->subscribedFeed($user, 'https://example.com/both.xml');
        $both->addTag($reading);
        $both->addTag($longReads);
        $this->unreadEntry($parentFeed, 'parent-entry', null);
        $this->unreadEntry($childFeed, 'child-entry', null);
        $this->unreadEntry($bothFeed, 'both-entry', null);

        $counts = $this->perTag($user);

        self::assertSame(3, $counts[$reading->requireId()] ?? 0);
        self::assertSame(2, $counts[$longReads->requireId()] ?? 0);
    }

    public function testAParentWithNoFeedsOfItsOwnStillCountsItsChildrens(): void
    {
        $user = $this->user();
        $reading = $this->tag($user, 'reading');
        $longReads = $this->childTag($user, 'long reads', $reading);
        [$childOnly, $childFeed] = $this->subscribedFeed($user, 'https://example.com/child.xml');
        $childOnly->addTag($longReads);
        $this->unreadEntry($childFeed, 'child-entry', null);

        $counts = $this->perTag($user);

        self::assertSame(1, $counts[$reading->requireId()] ?? 0);
        self::assertSame(1, $counts[$longReads->requireId()] ?? 0);
    }

    public function testAnotherUsersTagsOfTheSameFeedNeverCount(): void
    {
        $user = $this->user();
        $news = $this->tag($user, 'news');
        [$own, $feed] = $this->subscribedFeed($user, 'https://example.com/shared.xml');
        $own->addTag($news);
        $stranger = new User('stranger@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($stranger);
        $strangersTag = $this->tag($stranger, 'news');
        $strangers = new Subscription($stranger, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $strangers->addTag($strangersTag);
        $this->entityManager->persist($strangers);
        $this->unreadEntry($feed, 'shared-entry', null);

        self::assertSame([$news->requireId() => 1], $this->perTag($user));
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

        $perSubscription = $this->perSubscription($user);

        self::assertSame(9, $this->perNetwork($user)[SocialNetwork::Bluesky->value] ?? 0);
        self::assertSame(4, $perSubscription[$home->requireId()] ?? 0);
        self::assertSame(5, $perSubscription[$custom->requireId()] ?? 0);
    }

    public function testAScopeWithNothingUnreadIsAbsent(): void
    {
        $user = $this->user();
        $news = $this->tag($user, 'news');
        [$tagged] = $this->subscribedFeed($user, 'https://example.com/read.xml');
        $tagged->addTag($news);
        $this->subscribedFeed($user, 'social:bluesky:1:home', SourceFormat::BLUESKY_API);
        $this->entityManager->flush();

        self::assertSame([], $this->perTag($user));
        self::assertSame([], $this->perNetwork($user));
        self::assertSame([], $this->perNetworkProfiles($user));
    }

    public function testAFeedOnNoNetworkIsInNoNetworkCount(): void
    {
        $user = $this->user();
        [, $blueskyFeed] = $this->subscribedFeed($user, 'social:bluesky:1:home', SourceFormat::BLUESKY_API);
        [, $plainFeed] = $this->subscribedFeed($user, 'https://example.com/plain.xml');
        $this->unreadEntry($blueskyFeed, 'post', null);
        $this->unreadEntry($plainFeed, 'article', null);

        self::assertSame(
            [SocialNetwork::Bluesky->value => 1],
            $this->perNetwork($user),
        );
    }

    public function testNetworkProfilesCountOnlyProfileFeeds(): void
    {
        $user = $this->user();
        [, $homeFeed] = $this->subscribedFeed($user, 'social:bluesky:1:home', SourceFormat::BLUESKY_API);
        [, $profileFeed] = $this->subscribedFeed($user, 'https://bsky.app/profile/alice.bsky.social/rss');
        $this->unreadEntry($homeFeed, 'home-1', null);
        $this->unreadEntry($homeFeed, 'home-2', null);
        $this->unreadEntry($profileFeed, 'profile-1', null);

        self::assertSame(1, $this->perNetworkProfiles($user)[SocialNetwork::Bluesky->value] ?? 0);
        self::assertSame(3, $this->perNetwork($user)[SocialNetwork::Bluesky->value] ?? 0);
    }

    public function testEachNetworkCountsItsOwnCopy(): void
    {
        $user = $this->user();
        [, $blueskyFeed] = $this->subscribedFeed($user, 'social:bluesky:1:home', SourceFormat::BLUESKY_API);
        [, $mastodonFeed] = $this->subscribedFeed($user, 'social:mastodon:2:home', SourceFormat::MASTODON_API);
        $this->unreadEntry($blueskyFeed, 'bluesky-copy', 'shared-hash');
        $this->unreadEntry($mastodonFeed, 'mastodon-copy', 'shared-hash');

        $counts = $this->repository()->perNetwork($user->requireId(), $this->perSubscription($user));

        self::assertSame(1, $counts[SocialNetwork::Bluesky->value] ?? 0);
        self::assertSame(1, $counts[SocialNetwork::Mastodon->value] ?? 0);
    }

    public function testTheTagsGroupCountsATwiceTaggedFeedOnce(): void
    {
        $user = $this->user();
        $news = $this->tag($user, 'news');
        $tech = $this->tag($user, 'tech');
        [$subscription, $feed] = $this->subscribedFeed($user, 'https://example.com/a.xml');
        $subscription->addTag($news);
        $subscription->addTag($tech);
        [, $untaggedFeed] = $this->subscribedFeed($user, 'https://example.com/untagged.xml');
        $this->unreadEntry($feed, 'tagged', null);
        $this->unreadEntry($untaggedFeed, 'untagged', null);

        self::assertSame(
            [FeedGroup::Tags->value => 1, FeedGroup::Feeds->value => 1],
            $this->perFeedGroup($user),
        );
    }

    public function testTheFeedsGroupSkipsSocialFeedsAndCountsFeedsHiddenFromAllItems(): void
    {
        $user = $this->user();
        [$hidden, $hiddenFeed] = $this->subscribedFeed($user, 'https://example.com/hidden.xml');
        $hidden->setIncludeInAllItems(false);
        [, $homeFeed] = $this->subscribedFeed($user, 'social:bluesky:1:home', SourceFormat::BLUESKY_API);
        $this->unreadEntry($hiddenFeed, 'hidden', null);
        $this->unreadEntry($homeFeed, 'home', null);

        self::assertSame(
            [FeedGroup::Tags->value => 0, FeedGroup::Feeds->value => 1],
            $this->perFeedGroup($user),
        );
    }

    private function excludeBlueskyFrom(User $user, NetworkExclusionSurface $surface): void
    {
        $this->entityManager->persist(new NetworkExclusion($user, SocialNetwork::Bluesky, $surface));
        $this->entityManager->flush();
    }

    /** @return array<int, int> */
    private function perTag(User $user): array
    {
        return $this->repository()->perTag($user->requireId(), $this->perSubscription($user));
    }

    private function allItems(User $user): int
    {
        return $this->repository()->allItems($user->requireId(), $this->perSubscription($user));
    }

    /** @return array{tags: int, feeds: int} */
    private function perFeedGroup(User $user): array
    {
        return $this->repository()->perFeedGroup($user->requireId(), $this->perSubscription($user));
    }

    /** @return array<string, int> */
    private function perNetwork(User $user): array
    {
        return $this->repository()->perNetwork($user->requireId(), $this->perSubscription($user));
    }

    /** @return array<string, int> */
    private function perNetworkProfiles(User $user): array
    {
        return $this->repository()->perNetworkProfiles($user->requireId(), $this->perSubscription($user));
    }

    /** @return array<int, int> */
    private function perSubscription(User $user): array
    {
        return $this->repository()->perSubscription($user->requireId());
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

    private function childTag(User $user, string $name, Tag $parent): Tag
    {
        $child = new Tag($user, $name);
        $child->setParent($parent);
        $this->entityManager->persist($child);
        $this->entityManager->flush();

        return $child;
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
