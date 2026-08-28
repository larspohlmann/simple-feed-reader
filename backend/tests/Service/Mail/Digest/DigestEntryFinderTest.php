<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntrySearchQuery;
use App\Service\Mail\Digest\DigestEntryFinder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * DigestEntryFinder caps a saved search's unread-since matches for the digest
 * (#636) — the count callers use for "+N more" must stay the pre-cap total,
 * newest-first order comes straight from rowsByIdsForUser.
 */
final class DigestEntryFinderTest extends TestCase
{
    private EntryListRepository&MockObject $entries;
    private SavedSearch $search;
    private int $userId = 7;

    protected function setUp(): void
    {
        $this->entries = $this->createMock(EntryListRepository::class);

        $user = new User('digest@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->search = new SavedSearch($user, 'klima', false);
    }

    private function row(int $id): EntryListRow
    {
        $feed = new Feed('https://example.com/feed.xml');
        $entry = new Entry(
            $feed,
            'guid-' . $id,
            'https://example.com/' . $id,
            'Title ' . $id,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );

        return new EntryListRow($entry, 1, 'Example', false, false, false, false, null, null);
    }

    public function testCapsTheHydratedRowsAtPerSearchButKeepsTheFullTotal(): void
    {
        $ids = range(1, 12);
        $rows = array_map($this->row(...), $ids);
        $since = new \DateTimeImmutable('2026-07-15T00:00:00Z');

        $this->entries->expects(self::once())
            ->method('unreadMatchIdsSince')
            ->with(self::isInstanceOf(EntrySearchQuery::class), $since)
            ->willReturn($ids);
        $this->entries->expects(self::once())
            ->method('rowsByIdsForUser')
            ->with($ids, $this->userId)
            ->willReturn($rows);

        $matches = (new DigestEntryFinder($this->entries))->matchesSince($this->search, $this->userId, $since);

        self::assertSame(12, $matches->totalCount);
        self::assertCount(DigestEntryFinder::PER_SEARCH, $matches->entries);
        self::assertSame(
            array_slice($rows, 0, DigestEntryFinder::PER_SEARCH),
            $matches->entries,
            'The cap must keep the newest-first order rowsByIdsForUser already returns.',
        );
    }

    public function testNoMatchesReturnsEmptyWithoutHydrating(): void
    {
        $since = new \DateTimeImmutable('2026-07-15T00:00:00Z');

        $this->entries->expects(self::once())
            ->method('unreadMatchIdsSince')
            ->willReturn([]);
        $this->entries->expects(self::never())->method('rowsByIdsForUser');

        $matches = (new DigestEntryFinder($this->entries))->matchesSince($this->search, $this->userId, $since);

        self::assertSame([], $matches->entries);
        self::assertSame(0, $matches->totalCount);
    }
}
