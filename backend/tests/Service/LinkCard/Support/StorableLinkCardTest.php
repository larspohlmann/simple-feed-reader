<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard\Support;

use App\Entity\LinkCardContent;
use App\Service\LinkCard\Support\StorableLinkCard;
use PHPUnit\Framework\TestCase;

final class StorableLinkCardTest extends TestCase
{
    public function testACardWithinTheBoundsIsKeptAsItIs(): void
    {
        $card = new LinkCardContent('https://example.com/a', 'Title', 'About', 'https://example.com/a.jpg', 'Example');

        self::assertEquals($card, StorableLinkCard::of($card));
    }

    public function testAUrlTooLongToStoreGivesNoCard(): void
    {
        self::assertNull(StorableLinkCard::of($this->card(url: 'https://example.com/' . str_repeat('a', 2029))));
        self::assertNotNull(StorableLinkCard::of($this->card(url: 'https://example.com/' . str_repeat('a', 2028))));
    }

    public function testAUrlThatIsNoWebPageGivesNoCard(): void
    {
        self::assertNull(StorableLinkCard::of($this->card(url: 'javascript:alert(1)')));
        self::assertNull(StorableLinkCard::of($this->card(url: '/relative/story')));
    }

    public function testAnImageUrlThatIsNotHttpsIsDropped(): void
    {
        self::assertNull(StorableLinkCard::of($this->card(imageUrl: 'http://example.com/a.jpg'))?->imageUrl);
        self::assertNull(StorableLinkCard::of($this->card(imageUrl: 'javascript:alert(1)'))?->imageUrl);
    }

    public function testABlankTitleGivesNoCard(): void
    {
        self::assertNull(StorableLinkCard::of($this->card(title: '  ')));
    }

    public function testAnImageUrlTooLongToStoreIsDropped(): void
    {
        $tooLong = 'https://example.com/' . str_repeat('i', 2029);
        $longest = 'https://example.com/' . str_repeat('i', 2028);

        self::assertNull(StorableLinkCard::of($this->card(imageUrl: $tooLong))?->imageUrl);
        self::assertSame($longest, StorableLinkCard::of($this->card(imageUrl: $longest))?->imageUrl);
    }

    public function testTheTitleAndTheSiteAreClampedToTheirColumns(): void
    {
        $card = StorableLinkCard::of($this->card(title: str_repeat('é', 600), siteName: str_repeat('s', 300)));

        self::assertNotNull($card);
        self::assertSame(str_repeat('é', 500), $card->title);
        self::assertSame(str_repeat('s', 255), $card->siteName);
    }

    public function testTheDescriptionIsCutOnAWordBoundary(): void
    {
        $card = StorableLinkCard::of($this->card(description: str_repeat('word ', 100)));

        self::assertSame(str_repeat('word ', 58) . 'word…', $card?->description);
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
