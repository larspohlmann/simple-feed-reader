<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\EntryCursor;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\FeedRepository;
use App\Repository\SavedSearchEntryQuery;
use App\Service\Search\Index\IndexMatches;
use App\Service\Search\IndexedSavedSearchEntries;
use App\Service\Search\SavedSearchEntriesResult;
use App\Service\Search\SavedSearchTerm;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

/**
 * IndexedSavedSearchEntries: runs one engine query per saved search, unions
 * the ids, and hydrates the union once through EntryListRepository. The
 * reader itself is faked, so this covers only what IndexedSavedSearchEntries
 * does with it — the hydration and security behaviour of rowsByIdsForUser is
 * EntryRowsByIdsTest's job.
 */
final class IndexedSavedSearchEntriesTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);

        $this->em->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );

        $this->em->flush();
    }

    private function entry(string $guid, string $effectiveDate = '2026-07-10T00:00:00Z'): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function markRead(Entry $entry): void
    {
        $state = new EntryState($this->user, $entry);
        $state->setIsHidden(true);
        $this->em->persist($state);
        $this->em->flush();
    }

    private function list(FakeMultiSearchReader $reader, SavedSearchEntryQuery $query): SavedSearchEntriesResult
    {
        $entryListRepository = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $entryListRepository);

        /** @var FeedRepository $feedRepository */
        $feedRepository = self::getContainer()->get(FeedRepository::class);

        return (new IndexedSavedSearchEntries($reader, $feedRepository, $entryListRepository))->list($query);
    }

    /** @param list<SavedSearchTerm> $savedSearches */
    private function query(
        array $savedSearches,
        bool $onlyUnread = false,
        ?EntryCursor $cursor = null,
        int $limit = 50,
    ): SavedSearchEntryQuery {
        return new SavedSearchEntryQuery(
            userId: $this->user->getId() ?? 0,
            savedSearches: $savedSearches,
            onlyUnread: $onlyUnread,
            cursor: $cursor,
            limit: $limit,
        );
    }

    public function testUnionsMatchesAcrossSearchesAndAttributesToTheFirst(): void
    {
        $newest = $this->entry('newest', '2026-07-13T00:00:00Z');
        $middle = $this->entry('middle', '2026-07-12T00:00:00Z');
        $oldest = $this->entry('oldest', '2026-07-11T00:00:00Z');

        // search 10 matched newest+middle, search 20 matched middle+oldest.
        $reader = new FakeMultiSearchReader([[
            new IndexMatches([$newest->getId() ?? 0, $middle->getId() ?? 0], []),
            new IndexMatches([$middle->getId() ?? 0, $oldest->getId() ?? 0], []),
        ]]);

        $result = $this->list($reader, $this->query([
            new SavedSearchTerm(10, SearchTerms::fromInput('newest middle')),
            new SavedSearchTerm(20, SearchTerms::fromInput('middle oldest')),
        ]));

        self::assertSame(['newest', 'middle', 'oldest'], array_map(
            static fn (EntryListRow $row): string => $row->entry->getGuid(),
            $result->rows,
        ));
        self::assertSame(3, $result->matchCount);
        self::assertSame([
            $newest->getId() => 10,
            $middle->getId() => 10, // first search in order wins the badge
            $oldest->getId() => 20,
        ], $result->savedSearchIds);
    }

    public function testTruncatesToTheLimitAndResumesPastTheLastCandidate(): void
    {
        $newest = $this->entry('newest', '2026-07-13T00:00:00Z');
        $middle = $this->entry('middle', '2026-07-12T00:00:00Z');
        $oldest = $this->entry('oldest', '2026-07-11T00:00:00Z');

        $reader = new FakeMultiSearchReader([[
            new IndexMatches([$newest->getId() ?? 0, $middle->getId() ?? 0, $oldest->getId() ?? 0], []),
        ]]);

        $result = $this->list(
            $reader,
            $this->query([new SavedSearchTerm(1, SearchTerms::fromInput('post'))], limit: 2),
        );

        self::assertSame(['newest', 'middle'], array_map(
            static fn (EntryListRow $row): string => $row->entry->getGuid(),
            $result->rows,
        ));
        self::assertNotNull($result->continuationRow);
        self::assertSame('middle', $result->continuationRow->entry->getGuid());
        self::assertSame(3, $result->matchCount);
        self::assertFalse(
            array_key_exists((int)($oldest->getId() ?? 0), $result->savedSearchIds),
            'Truncated row must not appear in savedSearchIds badge map.',
        );
        self::assertSame(
            [(int)($newest->getId() ?? 0), (int)($middle->getId() ?? 0)],
            array_keys($result->savedSearchIds),
        );
    }

    public function testTheUnreadFilterDropsReadRowsButStillResumesPastThem(): void
    {
        $readNewer = $this->entry('read-newer', '2026-07-13T00:00:00Z');
        $unread = $this->entry('unread', '2026-07-12T00:00:00Z');
        $readOlder = $this->entry('read-older', '2026-07-11T00:00:00Z');
        $this->markRead($readNewer);
        $this->markRead($readOlder);

        $reader = new FakeMultiSearchReader([[
            new IndexMatches([$readNewer->getId() ?? 0, $unread->getId() ?? 0, $readOlder->getId() ?? 0], []),
        ]]);

        $result = $this->list(
            $reader,
            $this->query([new SavedSearchTerm(1, SearchTerms::fromInput('post'))], onlyUnread: true, limit: 3),
        );

        self::assertSame(['unread'], array_map(
            static fn (EntryListRow $row): string => $row->entry->getGuid(),
            $result->rows,
        ));
        self::assertNotNull($result->continuationRow);
        self::assertSame('read-older', $result->continuationRow->entry->getGuid());
        self::assertSame(3, $result->matchCount, 'The engine frontier survives the unread filter.');
        self::assertFalse(
            array_key_exists((int)($readNewer->getId() ?? 0), $result->savedSearchIds),
            'Unread-filtered row must not appear in savedSearchIds badge map.',
        );
        self::assertFalse(
            array_key_exists((int)($readOlder->getId() ?? 0), $result->savedSearchIds),
            'Unread-filtered row must not appear in savedSearchIds badge map.',
        );
        self::assertSame(
            [(int)($unread->getId() ?? 0)],
            array_keys($result->savedSearchIds),
        );
    }

    public function testAGhostIdIsDroppedFromRowsButNotFromTheMatchCount(): void
    {
        $real = $this->entry('real');

        $reader = new FakeMultiSearchReader([[new IndexMatches([$real->getId() ?? 0, 999999], [])]]);

        $result = $this->list($reader, $this->query([new SavedSearchTerm(1, SearchTerms::fromInput('post'))]));

        self::assertSame(['real'], array_map(
            static fn (EntryListRow $row): string => $row->entry->getGuid(),
            $result->rows,
        ));
        self::assertSame(2, $result->matchCount);
    }

    public function testNoSavedSearchesReturnsEmptyWithoutAskingTheEngine(): void
    {
        $reader = new FakeMultiSearchReader();

        $result = $this->list($reader, $this->query([]));

        self::assertSame([], $result->rows);
        self::assertSame([], $result->savedSearchIds);
        self::assertSame(0, $result->matchCount);
        self::assertSame([], $reader->receivedRounds);
    }

    public function testAUserWithNoSubscriptionsReturnsEmptyWithoutAskingTheEngine(): void
    {
        $lonely = new User('lonely@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($lonely);
        $this->em->flush();
        $reader = new FakeMultiSearchReader();

        $result = (new IndexedSavedSearchEntries(
            $reader,
            self::getContainer()->get(FeedRepository::class),
            self::getContainer()->get(EntryListRepository::class),
        ))->list(new SavedSearchEntryQuery(
            userId: $lonely->getId() ?? 0,
            savedSearches: [new SavedSearchTerm(1, SearchTerms::fromInput('post'))],
        ));

        self::assertSame([], $result->rows);
        self::assertSame([], $result->savedSearchIds);
        self::assertSame(0, $result->matchCount);
        self::assertSame([], $reader->receivedRounds);
    }
}
