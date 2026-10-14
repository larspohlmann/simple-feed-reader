<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Entity\QuotedPostContent;
use App\Enum\ShareKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntryShareKindTest extends TestCase
{
    private static function entry(): Entry
    {
        $now = new \DateTimeImmutable('2026-10-11 09:00:00');

        return new Entry(new Feed('https://example.org/feed'), 'guid', null, 'Title', $now, $now);
    }

    public function testANewEntrySharesNothing(): void
    {
        self::assertNull(self::entry()->getShareKind());
    }

    public function testStoresWhatItShares(): void
    {
        $entry = self::entry();
        $entry->setShareKind(ShareKind::Link);

        self::assertSame(ShareKind::Link, $entry->getShareKind());
    }

    public function testTheBodyStandsInForTheSummaryOnlyOfATitledEntrySharingNothing(): void
    {
        $article = self::entry();
        $share = self::entry();
        $share->setShareKind(ShareKind::Link);
        $now = new \DateTimeImmutable();
        $untitled = new Entry(new Feed('https://example.org/feed'), 'g', null, '', $now, $now);
        $untitled->setHeadline(Headline::untitledPost());

        self::assertTrue($article->bodyStandsInForSummary());
        self::assertFalse($share->bodyStandsInForSummary());
        self::assertFalse($untitled->bodyStandsInForSummary());
    }

    /** @return iterable<string, array{?ShareKind}> */
    public static function kindsOtherThanQuote(): iterable
    {
        yield 'link' => [ShareKind::Link];
        yield 'media' => [ShareKind::Media];
        yield 'nothing' => [null];
    }

    #[DataProvider('kindsOtherThanQuote')]
    public function testReclassifyingAQuoteAsAnythingElseDropsTheQuotedPost(?ShareKind $kind): void
    {
        $entry = self::entry();
        $entry->shareQuotedPost(self::quote());

        $entry->setShareKind($kind);

        self::assertSame($kind, $entry->getShareKind());
        self::assertNull($entry->getQuotedPost());
    }

    public function testClassifyingAsQuoteKeepsTheQuotedPost(): void
    {
        $entry = self::entry();
        $entry->shareQuotedPost(self::quote());

        $entry->setShareKind(ShareKind::Quote);

        self::assertNotNull($entry->getQuotedPost());
    }

    private static function quote(): QuotedPostContent
    {
        return new QuotedPostContent('https://a.example/1', 'A', 'a.example', null, null, 'text', [], null);
    }
}
