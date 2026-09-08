<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\LeadFigureCaptions;
use PHPUnit\Framework\TestCase;

final class LeadFigureCaptionsTest extends TestCase
{
    private function captionsOf(string $html): LeadFigureCaptions
    {
        return LeadFigureCaptions::fromDocument(HtmlDocumentParser::parseOrNull($html));
    }

    public function testReturnsTheCaptionOfTheMatchingFigure(): void
    {
        $html = '<body><figure><img src="https://cdn.test/hero-photo.jpg">'
            . '<figcaption>Bild: Berti Kolbow-Lehradt</figcaption></figure></body>';

        $captions = $this->captionsOf($html);

        self::assertSame('Bild: Berti Kolbow-Lehradt', $captions->captionFor('https://cdn.test/hero-photo.jpg'));
    }

    public function testMatchesADifferentSizeRenditionOfTheSameAsset(): void
    {
        // ImageIdentity matches renditions of one photo by their distinctive
        // filename words (len >= 5, non-generic), as PageImageInventoryTest
        // documents; a size suffix alone must not defeat that match here.
        $html = '<body><figure><img src="https://cdn.test/mountain-vista-scene-1280x720.jpg">'
            . '<figcaption>Bild: Berti Kolbow-Lehradt</figcaption></figure></body>';

        $captions = $this->captionsOf($html);

        self::assertSame(
            'Bild: Berti Kolbow-Lehradt',
            $captions->captionFor('https://cdn.test/mountain-vista-scene.jpg'),
        );
    }

    public function testCollapsesInternalWhitespaceAndTrims(): void
    {
        $html = '<body><figure><img src="https://cdn.test/hero-photo.jpg">'
            . "<figcaption>  Bild:\n  Berti   Kolbow-Lehradt  </figcaption></figure></body>";

        $captions = $this->captionsOf($html);

        self::assertSame('Bild: Berti Kolbow-Lehradt', $captions->captionFor('https://cdn.test/hero-photo.jpg'));
    }

    public function testReturnsNullForAnUnrelatedUrl(): void
    {
        $html = '<body><figure><img src="https://cdn.test/hero-photo.jpg">'
            . '<figcaption>Caption text</figcaption></figure></body>';

        $captions = $this->captionsOf($html);

        self::assertNull($captions->captionFor('https://cdn.test/unrelated-shot.jpg'));
    }

    public function testReturnsNullForANullUrl(): void
    {
        $html = '<body><figure><img src="https://cdn.test/hero-photo.jpg">'
            . '<figcaption>Caption text</figcaption></figure></body>';

        self::assertNull($this->captionsOf($html)->captionFor(null));
    }

    public function testReturnsNullForAnEmptyUrl(): void
    {
        $html = '<body><figure><img src="https://cdn.test/hero-photo.jpg">'
            . '<figcaption>Caption text</figcaption></figure></body>';

        self::assertNull($this->captionsOf($html)->captionFor(''));
    }

    public function testReturnsNullForANonHttpUrl(): void
    {
        $html = '<body><figure><img src="https://cdn.test/hero-photo.jpg">'
            . '<figcaption>Caption text</figcaption></figure></body>';

        self::assertNull($this->captionsOf($html)->captionFor('javascript:alert(1)'));
    }

    public function testReturnsNullWhenNoFigureCarriesACaption(): void
    {
        $html = '<body><figure><img src="https://cdn.test/hero-photo.jpg"></figure></body>';

        self::assertNull($this->captionsOf($html)->captionFor('https://cdn.test/hero-photo.jpg'));
    }

    public function testSkipsAFigureWhoseCaptionCollapsesToEmpty(): void
    {
        $html = '<body><figure><img src="https://cdn.test/hero-photo.jpg">'
            . '<figcaption>   </figcaption></figure></body>';

        self::assertNull($this->captionsOf($html)->captionFor('https://cdn.test/hero-photo.jpg'));
    }

    public function testIgnoresAFigureWithoutAnImage(): void
    {
        $html = '<body><figure><figcaption>Orphan caption</figcaption></figure></body>';

        self::assertNull($this->captionsOf($html)->captionFor('https://cdn.test/hero-photo.jpg'));
    }

    public function testANullDocumentYieldsAnEmptyInstance(): void
    {
        $captions = LeadFigureCaptions::fromDocument(null);

        self::assertNull($captions->captionFor('https://cdn.test/hero-photo.jpg'));
    }

    public function testReturnsTheFirstMatchingFigureInDocumentOrder(): void
    {
        $html = '<body>'
            . '<figure><img src="https://cdn.test/hero-photo.jpg"><figcaption>First</figcaption></figure>'
            . '<figure><img src="https://cdn.test/hero-photo.jpg"><figcaption>Second</figcaption></figure>'
            . '</body>';

        self::assertSame('First', $this->captionsOf($html)->captionFor('https://cdn.test/hero-photo.jpg'));
    }
}
