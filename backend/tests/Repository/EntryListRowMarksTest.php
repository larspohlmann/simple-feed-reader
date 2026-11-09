<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\EntryListRowMarks;
use PHPUnit\Framework\TestCase;

final class EntryListRowMarksTest extends TestCase
{
    public function testAViewInstantMakesTheEntryViewed(): void
    {
        $marks = new EntryListRowMarks(viewedAt: new \DateTimeImmutable('2026-11-09T10:00:00Z'));

        self::assertTrue($marks->isViewed());
    }

    public function testNoViewInstantLeavesTheEntryUnviewed(): void
    {
        self::assertFalse((new EntryListRowMarks())->isViewed());
    }

    public function testAShareInstantMakesTheEntryShared(): void
    {
        $marks = new EntryListRowMarks(sharedAt: new \DateTimeImmutable('2026-11-09T10:00:00Z'));

        self::assertTrue($marks->isShared());
    }

    public function testNoShareInstantLeavesTheEntryUnshared(): void
    {
        self::assertFalse((new EntryListRowMarks())->isShared());
    }
}
