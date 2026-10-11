<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Share;
use App\Enum\ShareKind;
use PHPUnit\Framework\TestCase;

final class EntryShareTest extends TestCase
{
    private static function entry(): Entry
    {
        $now = new \DateTimeImmutable('2026-10-11 09:00:00');

        return new Entry(new Feed('https://example.org/feed'), 'guid', null, 'Title', $now, $now);
    }

    public function testANewEntrySharesNothing(): void
    {
        self::assertTrue(self::entry()->getShare()->isNone());
    }

    public function testStoresAShareWithItsTarget(): void
    {
        $entry = self::entry();
        $entry->setShare(Share::link('https://example.com/story'));

        self::assertSame(ShareKind::Link, $entry->getShare()->kind);
        self::assertSame('https://example.com/story', $entry->getShare()->url);
    }

    public function testATargetTooLongForItsColumnIsDropped(): void
    {
        $entry = self::entry();
        $entry->setShare(Share::media('https://example.com/' . str_repeat('a', 2048)));

        self::assertSame(ShareKind::Media, $entry->getShare()->kind);
        self::assertNull($entry->getShare()->url);
    }

    public function testTheBodyStandsInForTheSummaryOnlyOfATitledEntrySharingNothing(): void
    {
        $article = self::entry();
        $share = self::entry();
        $share->setShare(Share::link('https://example.com/story'));
        $now = new \DateTimeImmutable();
        $untitled = new Entry(new Feed('https://example.org/feed'), 'g', null, '', $now, $now);
        $untitled->markTitleDerived();

        self::assertTrue($article->bodyStandsInForSummary());
        self::assertFalse($share->bodyStandsInForSummary());
        self::assertFalse($untitled->bodyStandsInForSummary());
    }
}
