<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkCard\Support;

use App\Entity\LinkCardContent;
use App\Service\Content\LinkCard\Support\StorableLinkCard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorableLinkCardTest extends TestCase
{
    public function testACardWithinTheBoundsIsKeptAsItIs(): void
    {
        $card = new LinkCardContent('https://example.com/a', 'Title', 'About', 'https://example.com/a.jpg', 'Example');

        self::assertEquals($card, StorableLinkCard::fromCard($card));
    }

    public function testAUrlTooLongToStoreGivesNoCard(): void
    {
        self::assertNull(StorableLinkCard::fromCard($this->card(url: 'https://example.com/' . str_repeat('a', 2029))));
        self::assertNotNull(
            StorableLinkCard::fromCard($this->card(url: 'https://example.com/' . str_repeat('a', 2028))),
        );
    }

    public function testAUrlThatIsNoWebPageGivesNoCard(): void
    {
        self::assertNull(StorableLinkCard::fromCard($this->card(url: 'javascript:alert(1)')));
        self::assertNull(StorableLinkCard::fromCard($this->card(url: '/relative/story')));
    }

    public function testAnImageUrlThatIsNotHttpsIsDropped(): void
    {
        self::assertNull(StorableLinkCard::fromCard($this->card(imageUrl: 'http://example.com/a.jpg'))?->imageUrl);
        self::assertNull(StorableLinkCard::fromCard($this->card(imageUrl: 'javascript:alert(1)'))?->imageUrl);
    }

    public function testAUrlsLengthCountsCharactersNotBytes(): void
    {
        $longest = 'https://example.com/' . str_repeat('ü', 2028);

        self::assertSame(
            $longest,
            StorableLinkCard::fromCard($this->card(url: $longest, imageUrl: $longest))?->imageUrl,
        );
    }

    #[DataProvider('bareUrlDescriptions')]
    public function testABareUrlDescriptionIsDropped(string $description): void
    {
        self::assertNull(StorableLinkCard::fromCard($this->card(description: $description))?->description);
    }

    /** @return iterable<string, array{string}> */
    public static function bareUrlDescriptions(): iterable
    {
        yield 'the card\'s own URL' => ['https://example.com/a'];
        yield 'another URL' => ['http://other.example/b?c=d'];
        yield 'padded with spaces' => ["  https://other.example/b \n"];
    }

    public function testADescriptionHoldingAUrlAmongWordsStays(): void
    {
        $description = 'Read more at https://example.com/a today';

        self::assertSame(
            $description,
            StorableLinkCard::fromCard($this->card(description: $description))?->description,
        );
    }

    public function testABlankTitleGivesNoCard(): void
    {
        self::assertNull(StorableLinkCard::fromCard($this->card(title: '  ')));
    }

    public function testAnImageUrlTooLongToStoreIsDropped(): void
    {
        $tooLong = 'https://example.com/' . str_repeat('i', 2029);
        $longest = 'https://example.com/' . str_repeat('i', 2028);

        self::assertNull(StorableLinkCard::fromCard($this->card(imageUrl: $tooLong))?->imageUrl);
        self::assertSame($longest, StorableLinkCard::fromCard($this->card(imageUrl: $longest))?->imageUrl);
    }

    public function testTheTitleAndTheSiteAreClampedToTheirColumns(): void
    {
        $card = StorableLinkCard::fromCard($this->card(title: str_repeat('é', 600), siteName: str_repeat('ß', 300)));

        self::assertNotNull($card);
        self::assertSame(str_repeat('é', 500), $card->title);
        self::assertSame(str_repeat('ß', 255), $card->siteName);
    }

    public function testTheDescriptionIsCutOnAWordBoundary(): void
    {
        $card = StorableLinkCard::fromCard($this->card(description: str_repeat('word ', 100)));

        self::assertSame(str_repeat('word ', 58) . 'word…', $card?->description);
    }

    public function testTheCardUrlLosesItsTrackers(): void
    {
        $card = $this->card(url: 'https://www.heise.de/news/x.html?wt_mc=sm.red&utm_source=mastodon#Echobox=1');

        self::assertSame('https://www.heise.de/news/x.html', StorableLinkCard::fromCard($card)?->url);
    }

    private function card(
        string $url = 'https://example.com/a',
        string $title = 'Title',
        ?string $description = null,
        ?string $imageUrl = null,
        string $siteName = 'example.com',
    ): LinkCardContent {
        return new LinkCardContent($url, $title, $description, $imageUrl, $siteName);
    }
}
