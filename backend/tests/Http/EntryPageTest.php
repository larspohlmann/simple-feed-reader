<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Http\EntryCursor;
use App\Http\EntryPage;
use App\Repository\EntryListRow;
use App\Repository\EntryQuery;
use PHPUnit\Framework\TestCase;

final class EntryPageTest extends TestCase
{
    public function testAShortPageOffersNoNextCursor(): void
    {
        $page = EntryPage::of([], 50);

        self::assertSame([], $page['entries']);
        self::assertNull($page['nextCursor']);
    }

    public function testAFullPageOffersACursorFromTheLastRow(): void
    {
        $newerDate = new \DateTimeImmutable('2026-07-12T00:00:00Z');
        $olderDate = new \DateTimeImmutable('2026-07-10T00:00:00Z');
        // Rows arrive newest-first, the order EntryListRepository::listForUser
        // returns them in; the cursor must key off the OLDEST (last) row so a
        // client asking for "what comes after this page" gets older entries.
        $newer = $this->rowForEntry(3, $newerDate);
        $older = $this->rowForEntry(1, $olderDate);

        $page = EntryPage::of([$newer, $older], 2);

        self::assertNotNull($page['nextCursor']);
        $cursor = EntryCursor::decode($page['nextCursor']);
        self::assertNotNull($cursor);
        $expectedEffectiveDate = $olderDate->format(\DateTimeInterface::ATOM);
        self::assertSame($expectedEffectiveDate, $cursor->effectiveDate->format(\DateTimeInterface::ATOM));
        self::assertSame(1, $cursor->id);
        // Pins the cursor to the LAST row specifically: a regression that swaps
        // array_key_last() for array_key_first() in EntryPage would produce the
        // newer row's id (3) here instead, and this assertion names that failure.
        self::assertNotSame($newer->entry->getId(), $cursor->id);
    }

    public function testASingleRowAtLimitOneOffersACursor(): void
    {
        // A $limit of 1 must still require at least 1 row before offering a
        // cursor. A regression that raised the floor to 2 would make this one
        // row look like a short page and drop the cursor.
        $row = $this->rowForEntry(9, new \DateTimeImmutable('2026-07-12T00:00:00Z'));

        $page = EntryPage::of([$row], 1);

        self::assertNotNull($page['nextCursor']);
        $cursor = EntryCursor::decode($page['nextCursor']);
        self::assertNotNull($cursor);
        self::assertSame(9, $cursor->id);
    }

    /**
     * The clamp used to live in two places: the repository capped the rows it
     * read, and EntryPage re-derived the same cap from the raw request value to
     * decide whether the page was full. The two spellings had to stay
     * character-identical. They no longer exist — the query object clamps once
     * at construction and hands the effective size to both — and this states
     * the failure that would return if a caller ever passed the raw value
     * again.
     */
    public function testAFullPageFromARequestAboveTheCeilingStillOffersACursor(): void
    {
        $query = new EntryQuery(userId: 1, limit: EntryQuery::MAX_LIMIT + 50);
        $rows = [];
        for ($id = 1; $id <= EntryQuery::MAX_LIMIT; $id++) {
            $rows[] = $this->rowForEntry($id, new \DateTimeImmutable('2026-07-12T00:00:00Z'));
        }

        $page = EntryPage::of($rows, $query->limit);

        self::assertNotNull(
            $page['nextCursor'],
            'A page filled to MAX_LIMIT is full; passing the unclamped 150 here would read it as short.',
        );
    }

    public function testAnEntryWithNoIdRaisesALogicException(): void
    {
        // getId() ?? throw is the only guard between a not-yet-persisted entry
        // and a cursor built from a null id. rowForEntryWithoutId leaves the
        // id unset, exactly as a freshly constructed, unflushed Entry would.
        $row = $this->rowForEntryWithoutId(new \DateTimeImmutable('2026-07-12T00:00:00Z'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('An entry loaded from the database must have an id.');

        EntryPage::of([$row], 1);
    }

    /**
     * The regression this branch shipped: IndexedEntrySearch can hand back
     * fewer rows than the engine actually matched, because hydration drops
     * any id the caller's subscription join rejects. withMatchCount() must
     * decide "is there a next page" from that engine count, not count($rows),
     * or a full page of matches with one dropped id silently looks short.
     */
    public function testAFullMatchCountStillOffersACursorWhenARowWasDropped(): void
    {
        $row = $this->rowForEntry(9, new \DateTimeImmutable('2026-07-12T00:00:00Z'));

        // The engine matched 2 ids (matchCount: 2) but only 1 hydrated.
        $page = EntryPage::withMatchCount([$row], 2, 2);

        self::assertNotNull(
            $page['nextCursor'],
            'A row dropped after a full engine match must not end pagination.',
        );
        $cursor = EntryCursor::decode($page['nextCursor']);
        self::assertNotNull($cursor);
        self::assertSame(9, $cursor->id);
    }

    /**
     * A genuinely final page — the engine itself returned fewer ids than the
     * limit — must still end pagination. Fixing the truncation bug must not
     * turn into a cursor that never runs out.
     */
    public function testAShortMatchCountOffersNoNextCursorEvenWithRows(): void
    {
        $row = $this->rowForEntry(9, new \DateTimeImmutable('2026-07-12T00:00:00Z'));

        $page = EntryPage::withMatchCount([$row], 2, 1);

        self::assertNull($page['nextCursor']);
    }

    /**
     * The residual case named in the fix: a full page of matches where EVERY
     * id was dropped by hydration leaves no surviving row to build a cursor
     * from. Pagination ends here rather than crashing or fabricating a
     * cursor; a later app:search:reindex clears the ghost ids that caused it.
     */
    public function testAFullMatchCountWithNoSurvivingRowsOffersNoNextCursor(): void
    {
        $page = EntryPage::withMatchCount([], 2, 2);

        self::assertSame([], $page['entries']);
        self::assertNull($page['nextCursor']);
    }

    /**
     * withMatchCount() is what of() delegates to; passing count($rows) as the
     * match count must reproduce of()'s own behaviour exactly, so the two
     * never drift apart for the plain entry list.
     */
    public function testWithMatchCountEqualToRowCountMatchesOf(): void
    {
        $row = $this->rowForEntry(9, new \DateTimeImmutable('2026-07-12T00:00:00Z'));

        self::assertSame(
            EntryPage::of([$row], 1),
            EntryPage::withMatchCount([$row], 1, 1),
        );
    }

    private function rowForEntry(int $id, \DateTimeImmutable $effectiveDate): EntryListRow
    {
        $row = $this->rowForEntryWithoutId($effectiveDate);
        // Entry has no id setter: the id only exists once Doctrine assigns it,
        // and this test builds the row by hand without booting the kernel.
        $reflection = new \ReflectionProperty(Entry::class, 'id');
        $reflection->setValue($row->entry, $id);

        return $row;
    }

    private function rowForEntryWithoutId(\DateTimeImmutable $effectiveDate): EntryListRow
    {
        $entry = new Entry(
            new Feed('https://example.com/feed.xml'),
            'guid-no-id',
            'https://example.com/entry-no-id',
            'Angular ships',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $effectiveDate,
        );

        return new EntryListRow(
            entry: $entry,
            subscriptionId: 1,
            subscriptionTitle: 'Example',
            isRead: false,
            isFavorite: false,
            isKept: false,
            isViewed: false,
            markedReadUntil: null,
        );
    }
}
