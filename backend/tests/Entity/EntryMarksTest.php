<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EntryMarks;
use PHPUnit\Framework\TestCase;

final class EntryMarksTest extends TestCase
{
    private const string EARLIER = '2026-11-20T10:00:00Z';
    private const string LATER = '2026-11-27T10:00:00Z';

    public function testNoneCarriesNoMark(): void
    {
        $marks = EntryMarks::none();

        self::assertFalse($marks->isFavorite);
        self::assertFalse($marks->isKept);
        self::assertNull($marks->likedAt);
    }

    public function testWithersChangeOnlyTheirOwnMark(): void
    {
        $marks = EntryMarks::none()->withFavorite(true)->withKept(true)->withKept(false);

        self::assertTrue($marks->isFavorite);
        self::assertFalse($marks->isKept);
    }

    public function testAMergeKeepsTheEarlierLikeWhicheverSideHoldsIt(): void
    {
        $earlier = new \DateTimeImmutable(self::EARLIER);
        $later = new \DateTimeImmutable(self::LATER);

        self::assertEquals($earlier, (new EntryMarks(false, false, $later))->withMergedFrom(
            new EntryMarks(false, false, $earlier),
        )->likedAt);
        self::assertEquals($earlier, (new EntryMarks(false, false, $earlier))->withMergedFrom(
            new EntryMarks(false, false, $later),
        )->likedAt);
    }

    public function testAMergeTakesALikeFromEitherSideAlone(): void
    {
        $likedAt = new \DateTimeImmutable(self::LATER);

        $liked = new EntryMarks(false, false, $likedAt);

        self::assertEquals($likedAt, EntryMarks::none()->withMergedFrom($liked)->likedAt);
        self::assertEquals($likedAt, $liked->withMergedFrom(EntryMarks::none())->likedAt);
        self::assertNull(EntryMarks::none()->withMergedFrom(EntryMarks::none())->likedAt);
    }

    public function testAMergeKeepsEitherSidesFlags(): void
    {
        $merged = (new EntryMarks(true, false, null))->withMergedFrom(new EntryMarks(false, true, null));

        self::assertTrue($merged->isFavorite);
        self::assertTrue($merged->isKept);
    }
}
