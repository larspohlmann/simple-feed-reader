<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Entity\SharedLink;
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
        $entry->shareQuotedPost(self::quote(), null);

        $entry->setShareKind($kind);

        self::assertSame($kind, $entry->getShareKind());
        self::assertNull($entry->getQuotedPost());
    }

    public function testClassifyingAsQuoteKeepsTheQuotedPost(): void
    {
        $entry = self::entry();
        $entry->shareQuotedPost(self::quote(), null);

        $entry->setShareKind(ShareKind::Quote);

        self::assertNotNull($entry->getQuotedPost());
    }

    public function testAQuotePostShowsItsLinksPreview(): void
    {
        $entry = self::entry();
        $preview = new LinkCardContent('https://n.example/s', 'Story', 'D', 'https://n.example/s.jpg', 'n.example');

        $entry->shareQuotedPost(self::quote(), self::link($preview));

        self::assertEquals($preview, $entry->getQuotedPost()?->linkCard);
        self::assertSame('https://a.example/1', $entry->getQuotedPost()?->url);
    }

    public function testAQuoteWithoutALinkShowsNoCard(): void
    {
        $entry = self::entry();

        $entry->shareQuotedPost(self::quote(), null);

        self::assertNotNull($entry->getQuotedPost());
        self::assertNull($entry->getQuotedPost()->linkCard);
    }

    public function testAQuotesOwnCardIsNotStored(): void
    {
        $entry = self::entry();
        $card = new LinkCardContent('https://own.example/', 'Own', null, null, 'own.example');

        $entry->shareQuotedPost(self::quote()->withLinkCard($card), null);

        self::assertNull($entry->getQuotedPost()?->linkCard);
    }

    public function testClassifyingAsQuoteKeepsItsLink(): void
    {
        $entry = self::entry();
        $link = self::link(new LinkCardContent('https://n.example/s', 'S', null, null, 'n'));
        $entry->shareQuotedPost(self::quote(), $link);

        $entry->setShareKind(ShareKind::Quote);

        self::assertEquals($link->preview(), $entry->getQuotedPost()?->linkCard);
    }

    private static function link(LinkCardContent $preview): SharedLink
    {
        return new SharedLink('hash', $preview, null, new \DateTimeImmutable('2026-11-15 10:00:00'));
    }

    private static function quote(): QuotedPostContent
    {
        return new QuotedPostContent('https://a.example/1', 'A', 'a.example', null, null, 'text', [], null);
    }
}
