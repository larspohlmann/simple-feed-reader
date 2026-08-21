<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LazyImageSources;
use PHPUnit\Framework\TestCase;

final class LazyImageSourcesTest extends TestCase
{
    private LazyImageSources $lazyImages;

    protected function setUp(): void
    {
        $this->lazyImages = new LazyImageSources();
    }

    public function testPromotesLazySourceOverPlaceholder(): void
    {
        $source = $this->resolvedSource(
            '<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4="'
            . ' data-lazy-src="https://images.example.com/photo.jpg" alt="A">'
        );

        self::assertSame('https://images.example.com/photo.jpg', $source);
    }

    public function testPromotesLazySourceWhenSrcIsAbsent(): void
    {
        $source = $this->resolvedSource('<img data-src="https://images.example.com/photo.jpg" alt="A">');

        self::assertSame('https://images.example.com/photo.jpg', $source);
    }

    public function testTrimsWhitespaceAroundACandidate(): void
    {
        $source = $this->resolvedSource('<img data-src="  https://images.example.com/photo.jpg  ">');

        self::assertSame('https://images.example.com/photo.jpg', $source);
    }

    public function testPromotesTheFirstUrlOfALazySrcset(): void
    {
        $source = $this->resolvedSource(
            '<img src="data:image/gif;base64,R0lGOD"'
            . ' data-lazy-srcset="https://images.example.com/small.jpg 318w,'
            . ' https://images.example.com/large.jpg 811w">'
        );

        self::assertSame('https://images.example.com/small.jpg', $source);
    }

    public function testKeepsAnImageThatAlreadyHasARealSource(): void
    {
        $source = $this->resolvedSource(
            '<img src="https://images.example.com/real.jpg"'
            . ' data-lazy-src="https://images.example.com/lazy.jpg">'
        );

        self::assertSame('https://images.example.com/real.jpg', $source);
    }

    public function testPromotesARelativeCandidate(): void
    {
        $source = $this->resolvedSource('<img data-src="/media/photo.jpg">');

        self::assertSame('/media/photo.jpg', $source);
    }

    public function testIgnoresACandidateWithAnUnsafeScheme(): void
    {
        $html = $this->resolvedHtml('<img src="data:image/gif;base64,R0lGOD" data-src="javascript:alert(1)">');

        self::assertStringNotContainsString('<img', $html);
    }

    public function testPromotesTheSourceOfTheEnclosingPicture(): void
    {
        $source = $this->resolvedSource(
            '<picture><source srcset="https://images.example.com/small.jpg 384w,'
            . ' https://images.example.com/large.jpg 768w"><img alt="A"></picture>'
        );

        self::assertSame('https://images.example.com/small.jpg', $source);
    }

    public function testPromotesTheSourceOfAPictureThatSelfClosesIt(): void
    {
        $source = $this->resolvedSource(
            '<picture><source srcset="https://images.example.com/small.jpg 384w"/>'
            . '<img alt="A"></picture>'
        );

        self::assertSame('https://images.example.com/small.jpg', $source);
    }

    public function testPrefersTheImagesOwnCandidateOverThePictureSource(): void
    {
        $source = $this->resolvedSource(
            '<picture><source srcset="https://images.example.com/from-source.jpg 384w">'
            . '<img data-src="https://images.example.com/from-image.jpg" alt="A"></picture>'
        );

        self::assertSame('https://images.example.com/from-image.jpg', $source);
    }

    public function testSkipsAPictureSourceThatCarriesNoCandidate(): void
    {
        $source = $this->resolvedSource(
            '<picture><source media="(min-width: 40em)"><source type="image/webp"'
            . ' srcset="https://images.example.com/photo.webp 384w"><img alt="A"></picture>'
        );

        self::assertSame('https://images.example.com/photo.webp', $source);
    }

    public function testRemovesAPictureImageWhoseSourceHasAnUnsafeScheme(): void
    {
        $html = $this->resolvedHtml(
            '<picture><source srcset="javascript:alert(1) 384w"><img alt="A"></picture>'
        );

        self::assertStringNotContainsString('<img', $html);
    }

    public function testRemovesASourcelessImageThatNoPictureEncloses(): void
    {
        $html = $this->resolvedHtml(
            '<figure><source srcset="https://images.example.com/photo.jpg 384w">'
            . '<img alt="A"></figure>'
        );

        self::assertStringNotContainsString('<img', $html);
    }

    public function testRemovesAnImageWithNoUsableCandidate(): void
    {
        $html = $this->resolvedHtml(
            '<figure><img src="data:image/gif;base64,R0lGOD" alt="A"><figcaption>C</figcaption></figure>'
        );

        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('<figcaption>C</figcaption>', $html);
    }

    /** The `src` the resolver leaves on the first image, or null if it removed it. */
    private function resolvedSource(string $bodyHtml): ?string
    {
        $image = $this->resolvedDocument($bodyHtml)->getElementsByTagName('img')->item(0);

        return $image instanceof \Dom\Element ? $image->getAttribute('src') : null;
    }

    private function resolvedHtml(string $bodyHtml): string
    {
        return $this->resolvedDocument($bodyHtml)->saveHtml();
    }

    private function resolvedDocument(string $bodyHtml): \Dom\HTMLDocument
    {
        $document = \Dom\HTMLDocument::createFromString(
            '<html lang="en"><body>' . $bodyHtml . '</body></html>',
            LIBXML_NOERROR,
        );

        $this->lazyImages->resolveIn($document);

        return $document;
    }
}
