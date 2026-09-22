<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\EntryMembershipSweepRepository;
use App\Repository\SavedSearchEntryMembershipRepository;
use App\Repository\SavedSearchRepository;
use App\Tests\DbTestCase;

final class SavedSearchMembershipSweepRepositoriesTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('sweep@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);
        $this->feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($this->feed);
        $this->em->flush();
    }

    public function testTheCeilingIsTheHighestIdCreatedNoLaterThanTheInstant(): void
    {
        $old = $this->entry('a', createdAt: '2026-09-22T09:58:00');
        $this->entry('b', createdAt: '2026-09-22T09:59:30');

        $ceiling = $this->sweepEntries()->settledCeilingId(new \DateTimeImmutable('2026-09-22T09:59:00'));

        self::assertSame($old->getId(), $ceiling);
    }

    public function testTheCeilingIsZeroWhenNoEntryIsSettled(): void
    {
        $this->entry('a', createdAt: '2026-09-22T09:59:30');

        self::assertSame(0, $this->sweepEntries()->settledCeilingId(new \DateTimeImmutable('2026-09-22T09:59:00')));
    }

    public function testIdsBetweenWalksAscendingAboveTheMarkUpToTheCeilingAndStopsAtTheLimit(): void
    {
        $first = $this->entry('a');
        $second = $this->entry('b');
        $third = $this->entry('c');
        $this->entry('d');

        $ids = $this->sweepEntries()->idsBetween((int) $first->getId(), (int) $third->getId(), 1);
        self::assertSame([$second->getId()], $ids);

        $ids = $this->sweepEntries()->idsBetween((int) $first->getId(), (int) $third->getId(), 10);
        self::assertSame([$second->getId(), $third->getId()], $ids);
    }

    public function testFindBelowMarkOrdersByMarkThenIdAndSkipsCaughtUpSearches(): void
    {
        $caughtUp = $this->search('caught up');
        $caughtUp->advanceMatchedUpTo(100);
        $behind = $this->search('behind');
        $behind->advanceMatchedUpTo(40);
        $fresh = $this->search('fresh');
        $this->em->flush();

        $due = $this->searches()->findBelowMark(100);

        self::assertSame(
            [$fresh->getId(), $behind->getId()],
            array_map(static fn (SavedSearch $s): ?int => $s->getId(), $due),
        );
    }

    public function testInsertMissingAddsOnlyTheAbsentPairsAndReportsHowMany(): void
    {
        $search = $this->search('climate');
        $one = $this->entry('a');
        $two = $this->entry('b');
        $this->em->flush();
        $matchedAt = new \DateTimeImmutable('2026-09-22T10:00:00');

        $first = $this->memberships()->insertMissing([(int) $search->getId() => [(int) $one->getId()]], $matchedAt);
        $second = $this->memberships()->insertMissing(
            [(int) $search->getId() => [(int) $one->getId(), (int) $two->getId()]],
            $matchedAt,
        );

        self::assertSame(1, $first);
        self::assertSame(1, $second);
        self::assertSame(2, $this->memberships()->count(['savedSearch' => $search]));
    }

    public function testInsertMissingTakesAWholeGroupInOneCall(): void
    {
        $climate = $this->search('climate');
        $rocket = $this->search('rocket');
        $entry = $this->entry('a');
        $this->em->flush();

        $inserted = $this->memberships()->insertMissing(
            [
                (int) $climate->getId() => [(int) $entry->getId()],
                (int) $rocket->getId() => [(int) $entry->getId()],
            ],
            new \DateTimeImmutable('2026-09-22T10:00:00'),
        );

        self::assertSame(2, $inserted);
        self::assertSame(1, $this->memberships()->count(['savedSearch' => $climate]));
        self::assertSame(1, $this->memberships()->count(['savedSearch' => $rocket]));
    }

    public function testInsertMissingWithNoIdsInsertsNothing(): void
    {
        $search = $this->search('climate');
        $this->em->flush();

        $now = new \DateTimeImmutable();

        self::assertSame(0, $this->memberships()->insertMissing([(int) $search->getId() => []], $now));
        self::assertSame(0, $this->memberships()->insertMissing([], $now));
    }

    private function entry(string $guid, string $createdAt = '2026-07-01T00:00:00Z'): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable($createdAt),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function search(string $term): SavedSearch
    {
        $search = new SavedSearch($this->user, $term, false);
        $this->em->persist($search);

        return $search;
    }

    private function sweepEntries(): EntryMembershipSweepRepository
    {
        $repository = self::getContainer()->get(EntryMembershipSweepRepository::class);
        self::assertInstanceOf(EntryMembershipSweepRepository::class, $repository);

        return $repository;
    }

    private function searches(): SavedSearchRepository
    {
        $repository = self::getContainer()->get(SavedSearchRepository::class);
        self::assertInstanceOf(SavedSearchRepository::class, $repository);

        return $repository;
    }

    private function memberships(): SavedSearchEntryMembershipRepository
    {
        $repository = self::getContainer()->get(SavedSearchEntryMembershipRepository::class);
        self::assertInstanceOf(SavedSearchEntryMembershipRepository::class, $repository);

        return $repository;
    }
}
