<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Mail\Digest\DigestEntryFinder;
use App\Tests\DbTestCase;
use App\Tests\Support\SavedSearchMatchFixture;

/**
 * DigestEntryFinder caps a saved search's unread-since matches for the digest
 * (#636) — the count callers use for "+N more" must stay the pre-cap total,
 * newest-first order comes straight from the membership table (#1116).
 */
final class DigestEntryFinderTest extends DbTestCase
{
    private User $user;
    private Feed $feed;
    private SavedSearch $search;
    private \DateTimeImmutable $since;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('digest@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->entityManager->persist($this->feed);
        $this->entityManager->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->search = new SavedSearch($this->user, 'klima', false);
        $this->entityManager->persist($this->search);
        $this->entityManager->flush();

        $this->since = new \DateTimeImmutable('2026-07-15T00:00:00Z');
    }

    public function testHydratesOnlyThePerSearchNewestButKeepsTheFullTotal(): void
    {
        $newestFirst = [];
        for ($index = 12; $index >= 1; --$index) {
            $newestFirst[] = $this->member(\sprintf('2026-07-%02dT00:00:00Z', 15 + $index));
        }
        $this->member('2026-07-10T00:00:00Z');
        $this->hide($this->member('2026-07-16T00:00:00Z'));

        $matches = $this->finder()->matchesSince($this->search, $this->user->requireId(), $this->since);

        $expectedIds = array_map(static fn (Entry $entry): ?int => $entry->getId(), $newestFirst);

        self::assertSame(12, $matches->totalCount);
        self::assertCount(DigestEntryFinder::PER_SEARCH, $matches->entries);
        self::assertSame(\array_slice($expectedIds, 0, DigestEntryFinder::PER_SEARCH), $this->ids($matches->entries));
    }

    public function testNoMatchesReturnsEmptyWithoutHydrating(): void
    {
        $matches = $this->finder()->matchesSince($this->search, $this->user->requireId(), $this->since);

        self::assertSame([], $matches->entries);
        self::assertSame(0, $matches->totalCount);
    }

    public function testTheDigestWindowHoldsWhenTheUserAndSearchIdsDiffer(): void
    {
        $searches = new SavedSearchMatchFixture($this->entityManager);
        $stranger = new User('stranger@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($stranger);
        $reader = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($reader);
        $this->entityManager->flush();
        $searches->search($stranger, 'throwaway-one');
        $searches->search($stranger, 'throwaway-two');
        $readersSearch = $searches->search($reader, 'klima');
        $readersEntry = $searches->member($reader, $readersSearch, new \DateTimeImmutable('2026-07-20T00:00:00Z'));
        self::assertNotSame($reader->requireId(), $readersSearch->requireId(), 'Equal ids would hide a swap.');

        $matches = $this->finder()->matchesSince($readersSearch, $reader->requireId(), $this->since);

        self::assertSame(1, $matches->totalCount);
        self::assertSame([$readersEntry->getId()], $this->ids($matches->entries));
    }

    private function member(string $effectiveDate): Entry
    {
        $entry = new Entry(
            $this->feed,
            'guid-' . uniqid('', true),
            'https://example.com/' . uniqid('', true),
            'Title ' . $effectiveDate,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
        );
        $this->entityManager->persist($entry);
        $this->entityManager->persist(
            new SavedSearchEntry($this->search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00Z')),
        );
        $this->entityManager->flush();

        return $entry;
    }

    private function hide(Entry $entry): void
    {
        $state = new EntryState($this->user, $entry);
        $state->hide(new \DateTimeImmutable('2026-07-17T00:00:00Z'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();
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

    private function finder(): DigestEntryFinder
    {
        $members = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $members);
        $entries = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $entries);

        return new DigestEntryFinder($members, $entries);
    }
}
