<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\EntryCursor;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;
use App\Repository\FeedRepository;
use App\Service\Search\EntrySearchResult;
use App\Service\Search\IndexedEntrySearch;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

/**
 * IndexedEntrySearch: asks the index for entry ids, then hydrates them
 * through EntryListRepository::rowsByIdsForUser. The reader itself is faked, so
 * this covers only what IndexedEntrySearch does with it — the hydration and
 * security behaviour of rowsByIdsForUser is EntryRowsByIdsTest's job.
 */
final class IndexedEntrySearchTest extends DbTestCase
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

    private function search(FakeSearchIndexReader $reader, EntrySearchQuery $query): EntrySearchResult
    {
        $entryListRepository = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $entryListRepository);

        /** @var FeedRepository $feedRepository */
        $feedRepository = self::getContainer()->get(FeedRepository::class);

        return (new IndexedEntrySearch($reader, $feedRepository, $entryListRepository))->search($query);
    }

    public function testTheQueryTermsAndLimitReachTheReader(): void
    {
        $reader = new FakeSearchIndexReader();

        $this->search($reader, new EntrySearchQuery(
            userId: $this->user->getId() ?? 0,
            terms: SearchTerms::fromInput('angular signals'),
            limit: 10,
        ));

        self::assertNotNull($reader->received);
        self::assertSame(['angular', 'signals'], $reader->received->terms->terms);
        self::assertSame(10, $reader->received->limit);
    }

    public function testTheWholeWordModeReachesTheReaderWithTheTermsItQualifies(): void
    {
        // The mode used to be left behind here — IndexedEntrySearch unpacked
        // the terms and passed the list alone, so every index search ran as a
        // substring search however the user typed it (#450).
        $reader = new FakeSearchIndexReader();

        $this->search($reader, new EntrySearchQuery(
            userId: $this->user->getId() ?? 0,
            terms: SearchTerms::fromInput('angular '),
        ));

        self::assertNotNull($reader->received);
        self::assertTrue($reader->received->terms->isWholeWord);
    }

    public function testASubstringSearchReachesTheReaderAsOne(): void
    {
        $reader = new FakeSearchIndexReader();

        $this->search($reader, new EntrySearchQuery(
            userId: $this->user->getId() ?? 0,
            terms: SearchTerms::fromInput('angular'),
        ));

        self::assertNotNull($reader->received);
        self::assertFalse($reader->received->terms->isWholeWord);
    }

    public function testTheCallersSubscribedFeedIdsReachTheReader(): void
    {
        $reader = new FakeSearchIndexReader();
        $feedId = $this->feed->getId();
        self::assertNotNull($feedId);

        $this->search($reader, new EntrySearchQuery(
            userId: $this->user->getId() ?? 0,
            terms: SearchTerms::fromInput('angular'),
        ));

        self::assertNotNull($reader->received);
        self::assertSame([$feedId], $reader->received->feedIds);
    }

    public function testTheCursorIsPassedThrough(): void
    {
        $reader = new FakeSearchIndexReader();
        $cursor = new EntryCursor(new \DateTimeImmutable('2026-07-10T00:00:00Z'), 42);

        $this->search($reader, new EntrySearchQuery(
            userId: $this->user->getId() ?? 0,
            terms: SearchTerms::fromInput('angular'),
            cursor: $cursor,
        ));

        self::assertNotNull($reader->received);
        self::assertSame($cursor, $reader->received->cursor);
    }

    public function testTheReturnedRowsAreTheHydratedIdsAndCarryTheMatchedWords(): void
    {
        $entry = $this->entry('hit');
        $entryId = $entry->getId();
        self::assertNotNull($entryId);

        $reader = new FakeSearchIndexReader(entryIds: [$entryId], matchedWords: ['angular']);

        $result = $this->search($reader, new EntrySearchQuery(
            userId: $this->user->getId() ?? 0,
            terms: SearchTerms::fromInput('angular'),
        ));

        self::assertCount(1, $result->rows);
        self::assertSame('hit', $result->rows[0]->entry->getGuid());
        self::assertSame(['angular'], $result->matchedWords);
    }

    /**
     * The bug this branch fixes. A ghost id — one the engine still returns
     * but that no longer hydrates, exactly what a failed async
     * EntryIndexer::forget() leaves behind — must not shrink matchCount. The
     * caller (SearchPage, via EntryPage::withMatchCount) needs the engine's
     * own count to keep offering a cursor, even though only one row survived.
     */
    public function testAGhostIdIsDroppedFromRowsButNotFromTheMatchCount(): void
    {
        $entry = $this->entry('hit');
        $entryId = $entry->getId();
        self::assertNotNull($entryId);
        $ghostId = $entryId + 1_000_000;

        $reader = new FakeSearchIndexReader(entryIds: [$entryId, $ghostId]);

        $result = $this->search($reader, new EntrySearchQuery(
            userId: $this->user->getId() ?? 0,
            terms: SearchTerms::fromInput('angular'),
            limit: 2,
        ));

        self::assertCount(1, $result->rows, 'The ghost id must not hydrate into a row.');
        self::assertSame(
            2,
            $result->matchCount,
            'matchCount must stay at the engine count, not fall to the surviving row count.',
        );
    }

    public function testAUserWithNoSubscriptionsReturnsEmptyWithoutAskingTheEngine(): void
    {
        $lonelyUser = new User('lonely@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($lonelyUser);
        $this->em->flush();

        $reader = new FakeSearchIndexReader();

        $result = $this->search($reader, new EntrySearchQuery(
            userId: $lonelyUser->getId() ?? 0,
            terms: SearchTerms::fromInput('angular'),
        ));

        self::assertSame([], $result->rows);
        self::assertSame([], $result->matchedWords);
        self::assertNull($reader->received);
    }
}
