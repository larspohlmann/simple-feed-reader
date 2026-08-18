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

        return $image instanceof \DOMElement ? $image->getAttribute('src') : null;
    }

    private function resolvedHtml(string $bodyHtml): string
    {
        return (string) $this->resolvedDocument($bodyHtml)->saveHTML();
    }

    private function resolvedDocument(string $bodyHtml): \DOMDocument
    {
        $document = new \DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<html lang="en"><body>' . $bodyHtml . '</body></html>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }

        $this->lazyImages->resolveIn($document);

        return $document;
    }
}
