<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\EntryListRowMarks;
use PHPUnit\Framework\TestCase;

final class EntryListRowMarksTest extends TestCase
{
    public function testAViewInstantMakesTheEntryViewed(): void
    {
        $marks = new EntryListRowMarks(
            isHidden: false,
            isFavorite: false,
            isKept: false,
            viewedAt: new \DateTimeImmutable('2026-11-09T10:00:00Z'),
            sharedAt: null,
        );

        self::assertTrue($marks->isViewed());
    }

    public function testNoViewInstantLeavesTheEntryUnviewed(): void
    {
        $marks = new EntryListRowMarks(
            isHidden: false,
            isFavorite: false,
            isKept: false,
            viewedAt: null,
            sharedAt: null,
        );

        self::assertFalse($marks->isViewed());
    }

    public function testAShareInstantMakesTheEntryShared(): void
    {
        $marks = new EntryListRowMarks(
            isHidden: false,
            isFavorite: false,
            isKept: false,
            viewedAt: null,
            sharedAt: new \DateTimeImmutable('2026-11-09T10:00:00Z'),
        );

        self::assertTrue($marks->isShared());
    }

    public function testNoShareInstantLeavesTheEntryUnshared(): void
    {
        $marks = new EntryListRowMarks(
            isHidden: false,
            isFavorite: false,
            isKept: false,
            viewedAt: null,
            sharedAt: null,
        );

        self::assertFalse($marks->isShared());
    }
}
