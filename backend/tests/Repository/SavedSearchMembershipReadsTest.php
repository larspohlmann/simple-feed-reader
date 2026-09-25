<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\EntryCursor;
use App\Repository\EntryListRow;
use App\Repository\SavedSearchEntryRepository;
use App\Repository\SavedSearchListQuery;
use App\Tests\DbTestCase;

/**
 * Every saved-search read over the membership table (#1116): the list, the
 * badge ids, the mark-read set, the digest window and the per-card badge.
 */
final class SavedSearchMembershipReadsTest extends DbTestCase
{
    private User $user;
    private User $stranger;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->stranger = new User('stranger@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);
        $this->em->persist($this->stranger);
        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);
        $this->em->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();
    }

    public function testTheListShowsMembersNewestFirstAndAnEntryInTwoSearchesOnce(): void
    {
        $climate = $this->search('climate');
        $rocket = $this->search('rocket');
        $both = $this->entry('a', '2026-07-10T00:00:00Z');
        $onlyRocket = $this->entry('b', '2026-07-09T00:00:00Z');
        $this->entry('c', '2026-07-08T00:00:00Z');
        $this->member($climate, $both);
        $this->member($rocket, $both);
        $this->member($rocket, $onlyRocket);

        $rows = $this->repo()->listMembers($this->query([$climate, $rocket]));

        self::assertSame([$both->getId(), $onlyRocket->getId()], $this->ids($rows));
    }

    public function testOnlyUnreadReturnsTheNewestUnreadRowsWhenTheNewestMembersAreRead(): void
    {
        $climate = $this->search('climate');
        $newestRead = $this->entry('a', '2026-07-10T00:00:00Z');
        $secondRead = $this->entry('b', '2026-07-09T00:00:00Z');
        $olderUnread = $this->entry('c', '2026-07-08T00:00:00Z');
        foreach ([$newestRead, $secondRead, $olderUnread] as $entry) {
            $this->member($climate, $entry);
        }
        $this->hide($newestRead);
        $this->hide($secondRead);

        $rows = $this->repo()->listMembers($this->query([$climate], onlyUnread: true, limit: 2));

        self::assertSame([$olderUnread->getId()], $this->ids($rows));
    }

    public function testTheCursorWalksTheStreamAcrossAPageBoundary(): void
    {
        $climate = $this->search('climate');
        $first = $this->entry('a', '2026-07-10T00:00:00Z');
        $second = $this->entry('b', '2026-07-09T00:00:00Z');
        $third = $this->entry('c', '2026-07-08T00:00:00Z');
        foreach ([$first, $second, $third] as $entry) {
            $this->member($climate, $entry);
        }

        $page = $this->repo()->listMembers($this->query([$climate], limit: 2));
        self::assertSame([$first->getId(), $second->getId()], $this->ids($page));

        $next = $this->repo()->listMembers($this->query(
            [$climate],
            limit: 2,
            cursor: new EntryCursor($second->getEffectiveDate(), (int) $second->getId()),
        ));
        self::assertSame([$third->getId()], $this->ids($next));
    }

    public function testAMemberOfAnUnsubscribedFeedIsNotListed(): void
    {
        $climate = $this->search('climate');
        $otherFeed = new Feed('https://elsewhere.example.com/feed.xml');
        $this->em->persist($otherFeed);
        $this->em->flush();
        $foreign = $this->entry('a', '2026-07-10T00:00:00Z', $otherFeed);
        $this->member($climate, $foreign);

        self::assertSame([], $this->repo()->listMembers($this->query([$climate])));
    }

    public function testAForeignSearchIdYieldsNothing(): void
    {
        $theirs = new SavedSearch($this->stranger, 'climate', false);
        $this->em->persist($theirs);
        $this->em->flush();
        $entry = $this->entry('a', '2026-07-10T00:00:00Z');
        $this->member($theirs, $entry);

        self::assertSame([], $this->repo()->listMembers($this->query([$theirs])));
        self::assertSame([(int) $theirs->getId() => []], $this->repo()->unreadMemberIdsBySavedSearch(
            (int) $this->user->getId(),
            [(int) $theirs->getId()],
        ));
    }

    public function testNoSearchIdsListsNothing(): void
    {
        self::assertSame([], $this->repo()->listMembers($this->query([])));
    }

    public function testDuplicateCopiesCollapseToTheLowestIdInTheList(): void
    {
        $climate = $this->search('climate');
        $original = $this->entry('a', '2026-07-10T00:00:00Z', urlHash: 'same');
        $copy = $this->entry('b', '2026-07-10T00:00:00Z', urlHash: 'same');
        $this->member($climate, $original);
        $this->member($climate, $copy);

        $rows = $this->repo()->listMembers($this->query([$climate]));

        self::assertSame([$original->getId()], $this->ids($rows));
    }

    public function testBadgeIdsGroupUnreadMembersBySearchWithEveryRequestedKeyPresent(): void
    {
        $climate = $this->search('climate');
        $rocket = $this->search('rocket');
        $empty = $this->search('zebra');
        $unread = $this->entry('a', '2026-07-10T00:00:00Z');
        $read = $this->entry('b', '2026-07-09T00:00:00Z');
        $this->member($climate, $unread);
        $this->member($climate, $read);
        $this->member($rocket, $unread);
        $this->hide($read);

        $ids = $this->repo()->unreadMemberIdsBySavedSearch(
            (int) $this->user->getId(),
            [(int) $climate->getId(), (int) $rocket->getId(), (int) $empty->getId()],
        );

        self::assertSame([
            (int) $climate->getId() => [$unread->getId()],
            (int) $rocket->getId() => [$unread->getId()],
            (int) $empty->getId() => [],
        ], $ids);
    }

    public function testBadgeIdsEqualTheUnreadListForTheSameFixture(): void
    {
        $climate = $this->search('climate');
        $newestRead = $this->entry('a', '2026-07-10T00:00:00Z');
        $older = $this->entry('b', '2026-07-09T00:00:00Z');
        $oldest = $this->entry('c', '2026-07-08T00:00:00Z');
        foreach ([$newestRead, $older, $oldest] as $entry) {
            $this->member($climate, $entry);
        }
        $this->hide($newestRead);

        $badge = $this->repo()->unreadMemberIdsBySavedSearch((int) $this->user->getId(), [(int) $climate->getId()]);
        $list = $this->repo()->listMembers($this->query([$climate], onlyUnread: true));

        self::assertCount(\count($badge[(int) $climate->getId()]), $list);
        self::assertEqualsCanonicalizing($badge[(int) $climate->getId()], $this->ids($list));
    }

    public function testMemberCountsCountReadAndUnreadMembersWithEveryRequestedKeyPresent(): void
    {
        $climate = $this->search('climate');
        $rocket = $this->search('rocket');
        $empty = $this->search('zebra');
        $unread = $this->entry('a', '2026-07-10T00:00:00Z');
        $read = $this->entry('b', '2026-07-09T00:00:00Z');
        $this->member($climate, $unread);
        $this->member($climate, $read);
        $this->member($rocket, $unread);
        $this->hide($read);

        $counts = $this->repo()->memberCountsBySavedSearch(
            (int) $this->user->getId(),
            [(int) $climate->getId(), (int) $rocket->getId(), (int) $empty->getId()],
        );

        self::assertSame([
            (int) $climate->getId() => 2,
            (int) $rocket->getId() => 1,
            (int) $empty->getId() => 0,
        ], $counts);
    }

    public function testMemberCountsIgnoreAnotherUsersSearches(): void
    {
        $theirs = new SavedSearch($this->stranger, 'climate', false);
        $this->em->persist($theirs);
        $this->em->flush();
        $this->member($theirs, $this->entry('a', '2026-07-10T00:00:00Z'));

        $counts = $this->repo()->memberCountsBySavedSearch((int) $this->user->getId(), [(int) $theirs->getId()]);

        self::assertSame([(int) $theirs->getId() => 0], $counts);
    }

    public function testTheMarkReadSetHonoursUntil(): void
    {
        $climate = $this->search('climate');
        $newer = $this->entry('a', '2026-07-10T00:00:00Z');
        $older = $this->entry('b', '2026-07-08T00:00:00Z');
        $this->member($climate, $newer);
        $this->member($climate, $older);

        $ids = $this->repo()->unreadMemberIdsUpTo(
            (int) $this->user->getId(),
            [(int) $climate->getId()],
            new \DateTimeImmutable('2026-07-09T00:00:00Z'),
        );

        self::assertSame([$older->getId()], $ids);
    }

    public function testTheDigestWindowIsUnreadMembersNewerThanSinceNewestFirst(): void
    {
        $climate = $this->search('climate');
        $newest = $this->entry('a', '2026-07-10T00:00:00Z');
        $inWindow = $this->entry('b', '2026-07-09T00:00:00Z');
        $tooOld = $this->entry('c', '2026-07-05T00:00:00Z');
        $readInWindow = $this->entry('d', '2026-07-09T12:00:00Z');
        foreach ([$newest, $inWindow, $tooOld, $readInWindow] as $entry) {
            $this->member($climate, $entry);
        }
        $this->hide($readInWindow);

        $ids = $this->repo()->unreadMemberIdsSince(
            (int) $climate->getId(),
            (int) $this->user->getId(),
            new \DateTimeImmutable('2026-07-08T00:00:00Z'),
        );

        self::assertSame([$newest->getId(), $inWindow->getId()], $ids);
    }

    /** @param list<SavedSearch> $searches */
    private function query(
        array $searches,
        bool $onlyUnread = false,
        int $limit = 50,
        ?EntryCursor $cursor = null,
    ): SavedSearchListQuery {
        return new SavedSearchListQuery(
            userId: (int) $this->user->getId(),
            savedSearchIds: array_map(static fn (SavedSearch $s): int => (int) $s->getId(), $searches),
            onlyUnread: $onlyUnread,
            cursor: $cursor,
            limit: $limit,
        );
    }

    private function search(string $term): SavedSearch
    {
        $search = new SavedSearch($this->user, $term, false);
        $this->em->persist($search);
        $this->em->flush();

        return $search;
    }

    private function entry(string $guid, string $effectiveDate, ?Feed $feed = null, ?string $urlHash = null): Entry
    {
        $entry = new Entry(
            $feed ?? $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
            $urlHash,
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function member(SavedSearch $search, Entry $entry): void
    {
        $this->em->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')));
        $this->em->flush();
    }

    private function hide(Entry $entry): void
    {
        $state = new EntryState($this->user, $entry);
        $state->hide(new \DateTimeImmutable('2026-07-11T00:00:00Z'));
        $this->em->persist($state);
        $this->em->flush();
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<int|null>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (EntryListRow $row): ?int => $row->entry->getId(), $rows);
    }

    private function repo(): SavedSearchEntryRepository
    {
        $repo = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $repo);

        return $repo;
    }
}
