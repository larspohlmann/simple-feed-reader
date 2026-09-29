<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Model;

use App\Service\Reader\Model\ImageIdentityModel;
use App\Service\Reader\Model\PageImageInventoryModel;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class PageImageInventoryModelTest extends TestCase
{
    use ParsesHtml;

    private function inventoryOf(string $html): PageImageInventoryModel
    {
        return PageImageInventoryModel::fromDocument($this->document($html));
    }

    public function testDrawsAPlainImageSource(): void
    {
        $inventory = $this->inventoryOf('<body><img src="https://cdn.test/hero-photo.jpg"></body>');

        self::assertTrue($inventory->draws(ImageIdentityModel::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testDrawsASizeVariantOfTheSamePhoto(): void
    {
        // ImageIdentityModel ties size variants by their photo-specific filename words (five letters or more),
        // so the example needs them: a pair that differs only by the size suffix does not match.
        $inventory = $this->inventoryOf('<body><img src="https://cdn.test/mountain-vista-scene-1280x720.jpg"></body>');

        self::assertTrue($inventory->draws(ImageIdentityModel::fromUrl('https://cdn.test/mountain-vista-scene.jpg')));
    }

    public function testDoesNotDrawAnUnrelatedPhoto(): void
    {
        $inventory = $this->inventoryOf('<body><img src="https://cdn.test/gallery-shot.jpg"></body>');

        self::assertFalse($inventory->draws(ImageIdentityModel::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testDrawsAPhotoThatIsNotTheFirstImageOnThePage(): void
    {
        // The lead can be any image the page draws, not only the first — a
        // decorative logo commonly precedes the article photo.
        $inventory = $this->inventoryOf(
            '<body><img src="https://cdn.test/site-logo.png">'
            . '<img src="https://cdn.test/hero-photo.jpg"></body>',
        );

        self::assertTrue($inventory->draws(ImageIdentityModel::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testDrawsTheFirstSrcsetCandidateOfASource(): void
    {
        $html = '<body><picture><source srcset="https://cdn.test/hero-photo.jpg 1x, https://cdn.test/hero-2x.jpg 2x">'
            . '<img></picture></body>';
        $inventory = $this->inventoryOf($html);

        self::assertTrue($inventory->draws(ImageIdentityModel::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testIgnoresAnImageWithAnEmptySource(): void
    {
        $inventory = $this->inventoryOf('<body><img src=""><img src="https://cdn.test/hero-photo.jpg"></body>');

        self::assertFalse($inventory->draws(ImageIdentityModel::fromUrl('https://cdn.test/other.jpg')));
        self::assertTrue($inventory->draws(ImageIdentityModel::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testTrimsSurroundingWhitespaceFromASource(): void
    {
        // The src attribute keeps its surrounding spaces; without trimming them
        // the URL fingerprints differently and would not match the same photo.
        $inventory = $this->inventoryOf('<body><img src="  https://cdn.test/hero-photo.jpg  "></body>');

        self::assertTrue($inventory->draws(ImageIdentityModel::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

    public function testADocumentWithNoImagesDrawsNothing(): void
    {
        $inventory = $this->inventoryOf('<body><p>Just words.</p></body>');

        self::assertFalse($inventory->draws(ImageIdentityModel::fromUrl('https://cdn.test/hero-photo.jpg')));
    }
}
