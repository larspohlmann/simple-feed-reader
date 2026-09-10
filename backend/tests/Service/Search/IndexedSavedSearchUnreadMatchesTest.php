<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryQuery;
use App\Repository\FeedRepository;
use App\Service\Search\Index\IndexMatches;
use App\Service\Search\IndexedSavedSearchEntries;
use App\Service\Search\IndexedSavedSearchUnreadMatches;
use App\Service\Search\SavedSearchTerm;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

/**
 * IndexedSavedSearchUnreadMatches: drives IndexedSavedSearchEntries's own
 * pagination to enumerate the engine-consistent unread match set for the
 * combined saved-search mark-read flow, rather than reimplementing the walk.
 */
final class IndexedSavedSearchUnreadMatchesTest extends DbTestCase
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

    private function source(FakeMultiSearchReader $reader): IndexedSavedSearchUnreadMatches
    {
        $entryListRepository = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $entryListRepository);
        /** @var FeedRepository $feedRepository */
        $feedRepository = self::getContainer()->get(FeedRepository::class);

        return new IndexedSavedSearchUnreadMatches(
            new IndexedSavedSearchEntries($reader, $feedRepository, $entryListRepository),
        );
    }

    /**
     * @param list<SavedSearchTerm> $savedSearches
     *
     * @return list<int>
     */
    private function markSet(FakeMultiSearchReader $reader, array $savedSearches, \DateTimeImmutable $until): array
    {
        return $this->source($reader)->unreadMatchIdsUpTo($this->user->getId() ?? 0, $savedSearches, $until);
    }

    public function testCollectsUnreadMatchesUpToTheWatermarkAndDropsReadOnes(): void
    {
        $unread = $this->entry('unread', '2026-07-12T00:00:00Z');
        $read = $this->entry('read', '2026-07-11T00:00:00Z');
        $this->markRead($read);

        $reader = new FakeMultiSearchReader([[new IndexMatches([$unread->getId() ?? 0, $read->getId() ?? 0], [])]]);

        $ids = $this->markSet(
            $reader,
            [new SavedSearchTerm(1, SearchTerms::fromInput('post'))],
            new \DateTimeImmutable('2026-07-13T00:00:00Z'),
        );

        self::assertSame([$unread->getId()], $ids);
    }

    public function testEmptySavedSearchesReturnEmptyWithoutAskingTheEngine(): void
    {
        $reader = new FakeMultiSearchReader();

        self::assertSame([], $this->markSet($reader, [], new \DateTimeImmutable('2026-07-13T00:00:00Z')));
        self::assertSame([], $reader->receivedRounds);
    }

    public function testPagesUntilAPartialPageThenStops(): void
    {
        // A full first page (matchCount == MAX_LIMIT) forces a second round; the
        // second is partial and ends it. Only a few ids are real entries — the rest
        // pad the engine frontier so matchCount reaches MAX_LIMIT without seeding 100 rows.
        $firstReal = $this->entry('first', '2026-07-12T00:00:00Z');
        $secondReal = $this->entry('second', '2026-07-10T00:00:00Z');
        $ghosts = range(900000, 900000 + EntryQuery::MAX_LIMIT - 2); // MAX_LIMIT-1 ghost ids
        $firstPage = new IndexMatches([$firstReal->getId() ?? 0, ...$ghosts], []); // MAX_LIMIT ids
        $secondPage = new IndexMatches([$secondReal->getId() ?? 0], []);            // partial → stop

        $reader = new FakeMultiSearchReader([[$firstPage], [$secondPage]]);

        $ids = $this->markSet(
            $reader,
            [new SavedSearchTerm(1, SearchTerms::fromInput('post'))],
            new \DateTimeImmutable('2026-07-13T00:00:00Z'),
        );

        self::assertSame([$firstReal->getId(), $secondReal->getId()], $ids);
        self::assertCount(2, $reader->receivedRounds, 'A full page must trigger exactly one more round.');
    }
}
