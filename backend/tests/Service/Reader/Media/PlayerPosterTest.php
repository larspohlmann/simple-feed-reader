<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\PlayerPoster;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class PlayerPosterTest extends TestCase
{
    private function holder(string $bodyHtml): Element
    {
        $document = HtmlDocumentParser::parseOrNull('<html><body>' . $bodyHtml . '</body></html>');
        self::assertNotNull($document);
        $holder = $document->querySelector('[data-v]');
        self::assertInstanceOf(Element::class, $holder);

        return $holder;
    }

    public function testTakesAnImageInsideTheHolder(): void
    {
        $holder = $this->holder('<div data-v="x"><img src="https://x.test/still.jpg"></div>');

        self::assertSame('https://x.test/still.jpg', PlayerPoster::near($holder));
    }

    /** tagesschau: the still sits in the player wrapper, one level above the element holding the URL. */
    public function testTakesAnImageBesideTheHolderInItsParent(): void
    {
        $holder = $this->holder(
            '<div class="wrapper"><picture><img src="https://x.test/still.jpg"></picture><div data-v="x"></div></div>',
        );

        self::assertSame('https://x.test/still.jpg', PlayerPoster::near($holder));
    }

    public function testReachesThreeLevelsUp(): void
    {
        $holder = $this->holder(
            '<section><img src="https://x.test/still.jpg"><div><div><div data-v="x"></div></div></div></section>',
        );

        self::assertSame('https://x.test/still.jpg', PlayerPoster::near($holder));
    }

    public function testDoesNotReachAFourthLevel(): void
    {
        $holder = $this->holder(
            '<section><img src="https://x.test/far.jpg"><div><div><div>'
            . '<div data-v="x"></div></div></div></div></section>',
        );

        self::assertNull(PlayerPoster::near($holder));
    }

    /** A shallow holder must not inherit the page's first picture, typically the logo. */
    public function testNeverSearchesTheBodyItself(): void
    {
        $holder = $this->holder('<img src="https://x.test/logo.svg"><div data-v="x"></div>');

        self::assertNull(PlayerPoster::near($holder));
    }

    public function testSkipsANonHttpsSource(): void
    {
        $holder = $this->holder(
            '<div data-v="x"><img src="data:image/gif;base64,R0lGOD"><img src="/relative.jpg">'
            . '<img src="https://x.test/still.jpg"></div>',
        );

        self::assertSame('https://x.test/still.jpg', PlayerPoster::near($holder));
    }

    public function testNullWhenThereIsNoImage(): void
    {
        $holder = $this->holder('<div><div data-v="x"><p>text</p></div></div>');

        self::assertNull(PlayerPoster::near($holder));
    }
}
