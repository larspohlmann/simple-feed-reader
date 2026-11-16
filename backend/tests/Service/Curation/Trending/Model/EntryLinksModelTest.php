<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Model;

use App\Service\Curation\Trending\Model\EntryLinksModel;
use App\Service\Curation\Trending\Model\WindowLinkCardModel;
use PHPUnit\Framework\TestCase;

final class EntryLinksModelTest extends TestCase
{
    public function testAStoryCardIsTheEntrysStoryCard(): void
    {
        $card = self::storyCard();

        self::assertSame($card, (new EntryLinksModel(null, null, $card))->storyCard());
    }

    public function testACardNamingNoStoryIsNoStoryCard(): void
    {
        self::assertNull((new EntryLinksModel(null, null, self::siteCard()))->storyCard());
    }

    public function testNoCardIsNoStoryCard(): void
    {
        self::assertNull((new EntryLinksModel('hash', 'https://outlet.example/storm', null))->storyCard());
    }

    public function testAnArticleEntrysSiteIsItsOwnBeforeItsCards(): void
    {
        self::assertSame(
            'outlet.example',
            (new EntryLinksModel('hash', 'https://www.outlet.example/storm', self::storyCard()))->articleSite(),
        );
    }

    public function testAPostsSiteIsItsStoryCards(): void
    {
        self::assertSame('shared.example', (new EntryLinksModel(null, null, self::storyCard()))->articleSite());
    }

    public function testAPostWhoseCardNamesNoStoryHasNoSite(): void
    {
        self::assertNull((new EntryLinksModel(null, null, self::siteCard()))->articleSite());
    }

    public function testAPostWithoutACardHasNoSite(): void
    {
        self::assertNull((new EntryLinksModel(null, null, null))->articleSite());
    }

    public function testTheUrlHashesAreTheEntrysAndItsCardsOnce(): void
    {
        self::assertSame(['own', 'hash'], (new EntryLinksModel('own', null, self::storyCard()))->urlHashes());
        self::assertSame(['hash'], (new EntryLinksModel('hash', null, self::storyCard()))->urlHashes());
    }

    public function testAMissingUrlHashIsLeftOut(): void
    {
        self::assertSame(['hash'], (new EntryLinksModel(null, null, self::storyCard()))->urlHashes());
        self::assertSame(['own'], (new EntryLinksModel('own', null, null))->urlHashes());
        self::assertSame([], (new EntryLinksModel(null, null, null))->urlHashes());
    }

    private static function storyCard(): WindowLinkCardModel
    {
        return new WindowLinkCardModel(
            1,
            'hash',
            'https://shared.example/2031/storm',
            'Storm floods the harbour',
            null,
            'Shared',
            null,
        );
    }

    private static function siteCard(): WindowLinkCardModel
    {
        return new WindowLinkCardModel(2, 'hash', 'https://shared.example/', 'Shared', null, 'Shared', null);
    }
}
