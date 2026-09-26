<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BackedUpReadMark;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class EntryStateTest extends TestCase
{
    public function testAFreshStateIsNotViewed(): void
    {
        $state = $this->makeState();

        self::assertFalse($state->isViewed());
        self::assertNull($state->getViewedAt());
    }

    public function testMarkViewedSetsFlagAndTimestamp(): void
    {
        $state = $this->makeState();
        $firstOpen = new \DateTimeImmutable('2026-08-07T10:00:00Z');

        $state->markViewed($firstOpen);

        self::assertTrue($state->isViewed());
        self::assertSame($firstOpen, $state->getViewedAt());
    }

    public function testMarkViewedKeepsTheFirstTimestamp(): void
    {
        $state = $this->makeState();
        $firstOpen = new \DateTimeImmutable('2026-08-07T10:00:00Z');
        $laterOpen = new \DateTimeImmutable('2026-08-07T11:00:00Z');

        $state->markViewed($firstOpen);
        $state->markViewed($laterOpen);

        self::assertTrue($state->isViewed());
        self::assertSame($firstOpen, $state->getViewedAt());
    }

    public function testMarkUnreadClearsBothReadAndViewed(): void
    {
        $state = $this->makeState();
        $when = new \DateTimeImmutable('2026-08-07T10:00:00Z');
        $state->hide($when);
        $state->markViewed($when);

        $state->markUnread();

        self::assertFalse($state->isHidden());
        self::assertNull($state->getHiddenAt());
        self::assertFalse($state->isViewed());
        self::assertNull($state->getViewedAt());
    }

    public function testMarkReadSetsFlagAndTimestampWithoutTouchingViewed(): void
    {
        $state = $this->makeState();
        $when = new \DateTimeImmutable('2026-08-07T10:00:00Z');

        $state->hide($when);

        self::assertTrue($state->isHidden());
        self::assertSame($when, $state->getHiddenAt());
        // A bare read (a mark-all-read sweep) never counts as "opened".
        self::assertFalse($state->isViewed());
    }

    public function testClearViewedLeavesTheEntryRead(): void
    {
        $state = $this->makeState();
        $when = new \DateTimeImmutable('2026-08-07T10:00:00Z');
        $state->hide($when);
        $state->markViewed($when);

        $state->clearViewed();

        // Un-ticking (#482) drops "Recently read" but keeps the entry read.
        self::assertFalse($state->isViewed());
        self::assertNull($state->getViewedAt());
        self::assertTrue($state->isHidden());
        self::assertSame($when, $state->getHiddenAt());
    }

    public function testMarkingAFavoriteLeavesTheOtherFlagsAlone(): void
    {
        $state = $this->makeState();

        $state->markFavorite();

        self::assertTrue($state->isFavorite());
        self::assertFalse($state->isKept());
        self::assertFalse($state->isHidden());
    }

    public function testClearingAFavoriteUndoesIt(): void
    {
        $state = $this->makeState();
        $state->markFavorite();

        $state->clearFavorite();

        self::assertFalse($state->isFavorite());
    }

    public function testMarkingKeptLeavesTheOtherFlagsAlone(): void
    {
        $state = $this->makeState();

        $state->markKept();

        self::assertTrue($state->isKept());
        self::assertFalse($state->isFavorite());
        self::assertFalse($state->isHidden());
    }

    public function testClearingKeptUndoesIt(): void
    {
        $state = $this->makeState();
        $state->markKept();

        $state->clearKept();

        self::assertFalse($state->isKept());
    }

    public function testARestoredLegacyReadMarkKeepsItsMissingInstant(): void
    {
        $state = $this->makeState();

        $state->restoreReadMark(new BackedUpReadMark(isHidden: true, hiddenAt: null));

        self::assertTrue($state->isHidden());
        self::assertNull($state->getHiddenAt());
    }

    public function testARestoredUnreadMarkKeepsAStaleInstantVerbatim(): void
    {
        $state = $this->makeState();
        $staleInstant = new \DateTimeImmutable('2026-08-03T00:00:00Z');

        $state->restoreReadMark(new BackedUpReadMark(isHidden: false, hiddenAt: $staleInstant));

        self::assertFalse($state->isHidden());
        self::assertSame($staleInstant, $state->getHiddenAt());
    }

    public function testRestoringAReadMarkLeavesTheOtherFlagsAlone(): void
    {
        $state = $this->makeState();
        $state->markFavorite();

        $readAt = new \DateTimeImmutable('2026-08-02T00:00:00Z');

        $state->restoreReadMark(new BackedUpReadMark(isHidden: true, hiddenAt: $readAt));

        self::assertTrue($state->isFavorite());
        self::assertFalse($state->isViewed());
    }

    private function makeState(): EntryState
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $feed = new Feed('https://example.com/feed.xml');
        $entry = new Entry(
            $feed,
            'guid-1',
            'https://example.com/1',
            'Post',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );

        return new EntryState($user, $entry);
    }
}
