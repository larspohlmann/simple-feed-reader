<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper\Pass;

use App\Service\Fetch\Pass\PageUrls;
use App\Service\Scraper\CardTitle;
use App\Service\Scraper\Pass\CardFields;
use App\Tests\Support\ParsesHtml;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class CardFieldsTest extends TestCase
{
    use ParsesHtml;

    private function cardFields(): CardFields
    {
        return new CardFields(new PageUrls('https://site.test/'), new CardTitle());
    }

    /** @return array{Element, Element} container + anchor from a snippet */
    private function card(string $html): array
    {
        $document = $this->document("<html lang=\"en\"><body>{$html}</body></html>");
        $container = $document->querySelector('[data-card]');
        \assert($container instanceof Element);
        $anchor = $container->tagName === 'A' ? $container : $container->querySelector('a');
        \assert($anchor instanceof Element);

        return [$container, $anchor];
    }

    public function testTitleFromHeadingAndTeaserFromLongestParagraph(): void
    {
        [$container, $anchor] = $this->card(<<<HTML
            <div data-card><a href="/a/1"><h3>A proper headline here</h3>
            <p>Short.</p>
            <p>This teaser paragraph is comfortably longer than forty characters in total.</p></a></div>
            HTML);
        $item = $this->cardFields()->item($container, $anchor);
        self::assertNotNull($item);
        self::assertSame('https://site.test/a/1', $item->url);
        self::assertSame('A proper headline here', $item->title);
        self::assertStringContainsString('comfortably longer', (string) $item->teaser);
    }

    public function testTitleFromClassNameWhenNoHeading(): void
    {
        [$container, $anchor] = $this->card(<<<HTML
            <a data-card href="/a/2"><span class="card__title-text">Span-only title text</span>
            <div class="byline">By Someone</div></a>
            HTML);
        $item = $this->cardFields()->item($container, $anchor);
        self::assertSame('Span-only title text', $item?->title);
    }

    public function testSiblingSubtitleClassDoesNotBeatTheTitleClass(): void
    {
        [$container, $anchor] = $this->card(<<<HTML
            <a data-card href="/a/10"><span class="card__title">Real card title</span>
            <span class="card__subtitle">Extra subtitle line that must never win</span></a>
            HTML);
        $item = $this->cardFields()->item($container, $anchor);
        self::assertSame('Real card title', $item?->title);
    }

    public function testTitleFallsBackToFirstAnchorTextLineNeverFullText(): void
    {
        [$container, $anchor] = $this->card(<<<HTML
            <a data-card href="/a/3">First line of the card
            <div>Second block that must not be part of the title but is long enough to matter here.</div></a>
            HTML);
        $item = $this->cardFields()->item($container, $anchor);
        self::assertSame('First line of the card', $item?->title);
    }

    public function testTitleFallbackSurvivesMinifiedHtmlWithoutNewlines(): void
    {
        [$container, $anchor] = $this->card(
            '<a data-card href="/a/9">Actual Title<div>By Jane Doe</div>'
            . '<div>A long teaser sentence well over forty characters for this test.</div></a>'
        );
        $item = $this->cardFields()->item($container, $anchor);
        self::assertSame('Actual Title', $item?->title);
    }

    public function testTeaserFromDataAttributeFallback(): void
    {
        [$container, $anchor] = $this->card(<<<HTML
            <a data-card href="/a/4"
                data-card-description="Attribute description text well over forty characters long for the fallback.">
            <span class="card__title">Attr card</span><div class="card__description"></div></a>
            HTML);
        $item = $this->cardFields()->item($container, $anchor);
        self::assertStringContainsString('Attribute description', (string) $item?->teaser);
    }

    public function testAriaDescribedbyIsNeverATeaser(): void
    {
        [$container, $anchor] = $this->card(<<<HTML
            <a data-card href="/a/8"
                aria-describedby="teaser-node-one teaser-node-two teaser-node-three teaser-node-four">
            <span class="card__title">Aria-described card</span></a>
            HTML);
        $item = $this->cardFields()->item($container, $anchor);
        self::assertNotNull($item);
        self::assertNull($item->teaser);
    }

    public function testImageAndTimeAndRejectsNonHttpLinks(): void
    {
        [$container, $anchor] = $this->card(<<<HTML
            <div data-card><a href="/a/5"><h2>With media data</h2></a>
            <img data-src="/img/pic.jpg" alt=""><time datetime="2026-07-20T10:00:00+02:00">yesterday</time></div>
            HTML);
        $item = $this->cardFields()->item($container, $anchor);
        self::assertSame('https://site.test/img/pic.jpg', $item?->imageUrl);
        self::assertSame('2026-07-20', $item->publishedAt?->format('Y-m-d'));

        [$unsafeContainer, $unsafeAnchor] = $this->card(
            '<div data-card><a href="javascript:alert(1)"><h2>Bad link</h2></a></div>'
        );
        self::assertNull($this->cardFields()->item($unsafeContainer, $unsafeAnchor));
    }

    public function testShortTitleRejected(): void
    {
        [$container, $anchor] = $this->card('<div data-card><a href="/a/6"><h2>Hi</h2></a></div>');
        self::assertNull($this->cardFields()->item($container, $anchor));
    }
}
