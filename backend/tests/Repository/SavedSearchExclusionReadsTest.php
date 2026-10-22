<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\SavedSearchNetworkInclusion;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\EntryListRow;
use App\Repository\SavedSearchEntryRepository;
use App\Repository\SavedSearchListQuery;
use App\Tests\DbTestCase;

/** A feed switched off, or a social network not switched on, drops out of every saved-search read (#1742). */
final class SavedSearchExclusionReadsTest extends DbTestCase
{
    private User $user;
    private SavedSearch $search;
    private Entry $kept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->search = new SavedSearch($this->user, 'climate', false);
        $this->entityManager->persist($this->search);
        $this->entityManager->flush();

        $this->kept = $this->memberOf($this->subscribedFeed('https://kept.example.com/feed.xml'), 'kept');
    }

    public function testAFeedSwitchedOffIsInNoRead(): void
    {
        $feed = $this->subscribedFeed('https://off.example.com/feed.xml');
        $this->subscriptionOf($feed)->setIncludeInSavedSearches(false);
        $this->memberOf($feed, 'off');

        $this->assertOnlyTheKeptEntryIsSeen();
    }

    public function testASocialFeedIsInNoReadWhileItsNetworkIsNotIncluded(): void
    {
        $this->memberOf(
            $this->subscribedFeed('https://bsky.app/profile/alice.bsky.social/rss', SocialNetwork::Bluesky),
            'post',
        );

        $this->assertOnlyTheKeptEntryIsSeen();
    }

    public function testIncludingTheNetworkBringsItsFeedsBack(): void
    {
        $post = $this->memberOf(
            $this->subscribedFeed('https://bsky.app/profile/alice.bsky.social/rss', SocialNetwork::Bluesky),
            'post',
        );
        $this->include(SocialNetwork::Bluesky);

        self::assertEqualsCanonicalizing(
            [$this->kept->getId(), $post->getId()],
            $this->listedIds(),
        );
    }

    public function testTheFeedSwitchStillAppliesInsideAnIncludedNetwork(): void
    {
        $this->include(SocialNetwork::Bluesky);
        $feed = $this->subscribedFeed('https://bsky.app/profile/bob.bsky.social/rss', SocialNetwork::Bluesky);
        $this->subscriptionOf($feed)->setIncludeInSavedSearches(false);
        $this->memberOf($feed, 'post');

        $this->assertOnlyTheKeptEntryIsSeen();
    }

    public function testIncludingOneNetworkLeavesTheOtherOut(): void
    {
        $this->include(SocialNetwork::Bluesky);
        $this->memberOf(
            $this->subscribedFeed('https://mastodon.example.com/@a.rss', SocialNetwork::Mastodon),
            'toot',
        );

        $this->assertOnlyTheKeptEntryIsSeen();
    }

    public function testAnExcludedLowestIdCopyDoesNotHideTheIncludedOne(): void
    {
        $offFeed = $this->subscribedFeed('https://off.example.com/feed.xml');
        $this->subscriptionOf($offFeed)->setIncludeInSavedSearches(false);
        $this->memberOf($offFeed, 'original', urlHash: 'same');
        $copy = $this->memberOf($this->subscribedFeed('https://on.example.com/feed.xml'), 'copy', urlHash: 'same');

        self::assertEqualsCanonicalizing([$this->kept->getId(), $copy->getId()], $this->listedIds());
    }

    public function testAnUnincludedNetworksLowestIdCopyDoesNotHideTheIncludedOne(): void
    {
        $this->memberOf(
            $this->subscribedFeed('https://bsky.app/profile/alice.bsky.social/rss', SocialNetwork::Bluesky),
            'original',
            urlHash: 'same',
        );
        $copy = $this->memberOf($this->subscribedFeed('https://on.example.com/feed.xml'), 'copy', urlHash: 'same');

        self::assertEqualsCanonicalizing([$this->kept->getId(), $copy->getId()], $this->listedIds());
    }

    private function assertOnlyTheKeptEntryIsSeen(): void
    {
        $userId = $this->user->requireId();
        $searchId = $this->search->requireId();
        $keptId = $this->kept->requireId();

        self::assertSame([$keptId], $this->listedIds(), 'list');
        self::assertSame(
            [$searchId => [$keptId]],
            $this->repository()->unreadMemberIdsBySavedSearch($userId, [$searchId]),
            'badge',
        );
        self::assertSame(
            [$searchId => 1],
            $this->repository()->memberCountsBySavedSearch($userId, [$searchId]),
            'count',
        );
        self::assertSame(
            [$keptId],
            $this->repository()->unreadMemberIdsUpTo(
                $userId,
                [$searchId],
                new \DateTimeImmutable('2026-12-31T00:00:00Z'),
            ),
            'mark-read',
        );
        self::assertSame(
            [$keptId],
            $this->repository()->unreadMemberIdsForUserSince(
                $userId,
                $searchId,
                new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            ),
            'digest',
        );
        $pillEntryIds = array_keys($this->repository()->savedSearchesByEntry($userId, $this->allEntryIds()));
        self::assertSame([$keptId], $pillEntryIds, 'pills');
    }

    private function subscribedFeed(string $url, ?SocialNetwork $network = null): Feed
    {
        $feed = new Feed($url);
        $feed->classifyAs($network);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(
            new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->entityManager->flush();
        self::assertSame($network, $feed->getNetwork(), 'the URL classifies the feed on flush');

        return $feed;
    }

    private function subscriptionOf(Feed $feed): Subscription
    {
        $subscription = $this->entityManager->getRepository(Subscription::class)->findOneBy(['feed' => $feed]);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }

    private function include(SocialNetwork $network): void
    {
        $this->entityManager->persist(new SavedSearchNetworkInclusion($this->user, $network));
        $this->entityManager->flush();
    }

    private function memberOf(Feed $feed, string $guid, ?string $urlHash = null): Entry
    {
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
            $urlHash,
        );
        $this->entityManager->persist($entry);
        $this->entityManager->persist(
            new SavedSearchEntry($this->search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')),
        );
        $this->entityManager->flush();

        return $entry;
    }

    /** @return list<int|null> */
    private function listedIds(): array
    {
        $rows = $this->repository()->listMembers(new SavedSearchListQuery(
            userId: $this->user->requireId(),
            savedSearchIds: [$this->search->requireId()],
            onlyUnread: false,
            cursor: null,
            limit: 50,
        ));

        return array_map(static fn (EntryListRow $row): ?int => $row->entry->getId(), $rows);
    }

    /** @return list<int> */
    private function allEntryIds(): array
    {
        return array_map(
            static fn (Entry $entry): int => $entry->requireId(),
            $this->entityManager->getRepository(Entry::class)->findAll(),
        );
    }

    private function repository(): SavedSearchEntryRepository
    {
        $repository = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $repository);

        return $repository;
    }
}
