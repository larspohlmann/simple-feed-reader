<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\NoscriptImageUnwrapper;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class NoscriptImageUnwrapperTest extends TestCase
{
    private NoscriptImageUnwrapper $unwrapper;

    protected function setUp(): void
    {
        $this->unwrapper = new NoscriptImageUnwrapper();
    }

    /** heise ships the real photo only inside <noscript>; the sanitizer drops the tag, so it must be promoted first. */
    public function testPromotesAnImageOutOfNoscript(): void
    {
        $html = $this->unwrapped('<figure><noscript><img src="https://x.test/real.jpg" alt="A"></noscript></figure>');

        self::assertStringNotContainsString('<noscript', $html);
        self::assertStringContainsString('<img src="https://x.test/real.jpg" alt="A">', $html);
    }

    public function testReplacesTheAdjacentPlaceholderWithTheNoscriptImage(): void
    {
        $html = $this->unwrapped(
            '<figure><img src="data:image/svg+xml,placeholder" alt="A">'
            . '<noscript><img src="https://x.test/real.jpg" alt="A"></noscript></figure>'
        );

        self::assertStringNotContainsString('<noscript', $html);
        self::assertStringNotContainsString('data:image', $html);
        self::assertSame(1, substr_count($html, '<img'));
        self::assertStringContainsString('src="https://x.test/real.jpg"', $html);
    }

    public function testLeavesANoscriptWithoutAnImageUntouched(): void
    {
        $html = $this->unwrapped('<noscript><p>Bitte aktivieren Sie JavaScript.</p></noscript>');

        self::assertStringContainsString('<noscript><p>Bitte aktivieren Sie JavaScript.</p></noscript>', $html);
    }

    /** A genuine content image is not a placeholder and must survive alongside the promoted noscript image. */
    public function testKeepsAGenuineImageThatPrecedesANoscriptOfADifferentPhoto(): void
    {
        $html = $this->unwrapped(
            '<figure><img src="https://x.test/genuine-a.jpg" alt="A">'
            . '<noscript><img src="https://x.test/photo-b.jpg" alt="B"></noscript></figure>'
        );

        self::assertStringNotContainsString('<noscript', $html);
        self::assertStringContainsString('src="https://x.test/genuine-a.jpg"', $html);
        self::assertStringContainsString('src="https://x.test/photo-b.jpg"', $html);
        self::assertSame(2, substr_count($html, '<img'));
    }

    /** Each promoted image must not be mistaken for the next noscript's placeholder. */
    public function testKeepsBothImagesAcrossConsecutiveNoscriptBlocks(): void
    {
        $html = $this->unwrapped(
            '<noscript><img src="https://x.test/photo-a.jpg" alt="A"></noscript>'
            . '<noscript><img src="https://x.test/photo-b.jpg" alt="B"></noscript>'
        );

        self::assertStringNotContainsString('<noscript', $html);
        self::assertStringContainsString('src="https://x.test/photo-a.jpg"', $html);
        self::assertStringContainsString('src="https://x.test/photo-b.jpg"', $html);
        self::assertSame(2, substr_count($html, '<img'));
    }

    /** Dedup still applies: a same-asset lazy-load placeholder is removed. */
    public function testRemovesAPrecedingSameAssetPlaceholder(): void
    {
        $html = $this->unwrapped(
            '<figure><img src="https://x.test/mountain-view-11111.jpg" alt="A">'
            . '<noscript><img src="https://x.test/mountain-view-11111-1280x720.jpg" alt="A"></noscript></figure>'
        );

        self::assertStringNotContainsString('<noscript', $html);
        self::assertStringNotContainsString('mountain-view-11111.jpg', $html);
        self::assertSame(1, substr_count($html, '<img'));
        self::assertStringContainsString('src="https://x.test/mountain-view-11111-1280x720.jpg"', $html);
    }

    private function unwrapped(string $bodyHtml): string
    {
        $document = HTMLDocument::createFromString(
            '<html lang="en"><body>' . $bodyHtml . '</body></html>',
            LIBXML_NOERROR,
        );
        $this->unwrapper->unwrapIn($document);

        return $document->saveHtml();
    }
}
