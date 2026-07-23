<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Scraper\CardFields;
use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class CardFieldsTest extends TestCase
{
    /** @return array{Element, Element} container + anchor from a snippet */
    private function card(string $html): array
    {
        $doc = HTMLDocument::createFromString("<html lang=\"en\"><body>{$html}</body></html>", \LIBXML_NOERROR);
        $container = $doc->querySelector('[data-card]');
        \assert($container instanceof Element);
        $anchor = $container->tagName === 'A' ? $container : $container->querySelector('a');
        \assert($anchor instanceof Element);

        return [$container, $anchor];
    }

    public function testTitleFromHeadingAndTeaserFromLongestParagraph(): void
    {
        [$c, $a] = $this->card(<<<HTML
            <div data-card><a href="/a/1"><h3>A proper headline here</h3>
            <p>Short.</p>
            <p>This teaser paragraph is comfortably longer than forty characters in total.</p></a></div>
            HTML);
        $item = CardFields::item($c, $a, 'https://site.test/');
        self::assertNotNull($item);
        self::assertSame('https://site.test/a/1', $item->url);
        self::assertSame('A proper headline here', $item->title);
        self::assertStringContainsString('comfortably longer', (string) $item->teaser);
    }

    public function testTitleFromClassNameWhenNoHeading(): void
    {
        [$c, $a] = $this->card(<<<HTML
            <a data-card href="/a/2"><span class="card__title-text">Span-only title text</span>
            <div class="byline">By Someone</div></a>
            HTML);
        $item = CardFields::item($c, $a, 'https://site.test/');
        self::assertSame('Span-only title text', $item?->title);
    }

    public function testTitleFallsBackToFirstAnchorTextLineNeverFullText(): void
    {
        [$c, $a] = $this->card(<<<HTML
            <a data-card href="/a/3">First line of the card
            <div>Second block that must not be part of the title but is long enough to matter here.</div></a>
            HTML);
        $item = CardFields::item($c, $a, 'https://site.test/');
        self::assertSame('First line of the card', $item?->title);
    }

    public function testTeaserFromDataAttributeFallback(): void
    {
        [$c, $a] = $this->card(<<<HTML
            <a data-card href="/a/4"
                data-card-description="Attribute description text well over forty characters long for the fallback.">
            <span class="card__title">Attr card</span><div class="card__description"></div></a>
            HTML);
        $item = CardFields::item($c, $a, 'https://site.test/');
        self::assertStringContainsString('Attribute description', (string) $item?->teaser);
    }

    public function testAriaDescribedbyIsNeverATeaser(): void
    {
        [$c, $a] = $this->card(<<<HTML
            <a data-card href="/a/8"
                aria-describedby="teaser-node-one teaser-node-two teaser-node-three teaser-node-four">
            <span class="card__title">Aria-described card</span></a>
            HTML);
        $item = CardFields::item($c, $a, 'https://site.test/');
        self::assertNotNull($item);
        self::assertNull($item->teaser);
    }

    public function testImageAndTimeAndRejectsNonHttpLinks(): void
    {
        [$c, $a] = $this->card(<<<HTML
            <div data-card><a href="/a/5"><h2>With media data</h2></a>
            <img data-src="/img/pic.jpg" alt=""><time datetime="2026-07-20T10:00:00+02:00">yesterday</time></div>
            HTML);
        $item = CardFields::item($c, $a, 'https://site.test/');
        self::assertSame('https://site.test/img/pic.jpg', $item?->imageUrl);
        self::assertSame('2026-07-20', $item->publishedAt?->format('Y-m-d'));

        [$c2, $a2] = $this->card('<div data-card><a href="javascript:alert(1)"><h2>Bad link</h2></a></div>');
        self::assertNull(CardFields::item($c2, $a2, 'https://site.test/'));
    }

    public function testShortTitleRejected(): void
    {
        [$c, $a] = $this->card('<div data-card><a href="/a/6"><h2>Hi</h2></a></div>');
        self::assertNull(CardFields::item($c, $a, 'https://site.test/'));
    }
}
