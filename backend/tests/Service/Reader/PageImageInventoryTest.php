<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\ImageIdentity;
use App\Service\Reader\PageImageInventory;
use PHPUnit\Framework\TestCase;

final class PageImageInventoryTest extends TestCase
{
    private function inventoryOf(string $html): PageImageInventory
    {
        return PageImageInventory::fromDocument(HtmlDocumentParser::parseOrNull($html));
    }

    public function testDrawsAPlainImageSource(): void
    {
        $inventory = $this->inventoryOf('<body><img src="https://cdn.test/hero-photo.jpg"></body>');

        self::assertTrue($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testDrawsASizeVariantOfTheSamePhoto(): void
    {
        // ImageIdentity matches renditions of one photo by their distinctive
        // filename words (the WordPress size-variant case in its docblock). The
        // example must carry photo-specific tokens (len >= 5, non-generic): a
        // pair distinguished only by the size suffix does NOT match, and must
        // not, since ImageIdentity is untouched here (spec non-goal).
        $inventory = $this->inventoryOf('<body><img src="https://cdn.test/mountain-vista-scene-1280x720.jpg"></body>');

        self::assertTrue($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/mountain-vista-scene.jpg')));
    }

    public function testDoesNotDrawAnUnrelatedPhoto(): void
    {
        $inventory = $this->inventoryOf('<body><img src="https://cdn.test/gallery-shot.jpg"></body>');

        self::assertFalse($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testDrawsAPhotoThatIsNotTheFirstImageOnThePage(): void
    {
        // The lead can be any image the page draws, not only the first — a
        // decorative logo commonly precedes the article photo.
        $inventory = $this->inventoryOf(
            '<body><img src="https://cdn.test/site-logo.png">'
            . '<img src="https://cdn.test/hero-photo.jpg"></body>',
        );

        self::assertTrue($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testDrawsTheFirstSrcsetCandidateOfASource(): void
    {
        $html = '<body><picture><source srcset="https://cdn.test/hero-photo.jpg 1x, https://cdn.test/hero-2x.jpg 2x">'
            . '<img></picture></body>';
        $inventory = $this->inventoryOf($html);

        self::assertTrue($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testIgnoresAnImageWithAnEmptySource(): void
    {
        $inventory = $this->inventoryOf('<body><img src=""><img src="https://cdn.test/hero-photo.jpg"></body>');

        self::assertFalse($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/other.jpg')));
        self::assertTrue($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testTrimsSurroundingWhitespaceFromASource(): void
    {
        // The src attribute keeps its surrounding spaces; without trimming them
        // the URL fingerprints differently and would not match the same photo.
        $inventory = $this->inventoryOf('<body><img src="  https://cdn.test/hero-photo.jpg  "></body>');

        self::assertTrue($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testANullDocumentDrawsNothing(): void
    {
        $inventory = PageImageInventory::fromDocument(null);

        self::assertFalse($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testADocumentWithNoImagesDrawsNothing(): void
    {
        $inventory = $this->inventoryOf('<body><p>Just words.</p></body>');

        self::assertFalse($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }
}
