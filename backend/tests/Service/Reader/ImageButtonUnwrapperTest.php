<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\ImageButtonUnwrapper;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class ImageButtonUnwrapperTest extends TestCase
{
    private ImageButtonUnwrapper $unwrapper;

    protected function setUp(): void
    {
        $this->unwrapper = new ImageButtonUnwrapper();
    }

    /** bild.de article 79376876: each article photo sits inside a lightbox-trigger <button>; readability drops every button with its subtree, orphaning the figcaption. */
    public function testPromotesAContentPhotoOutOfItsButtonWrapper(): void
    {
        $html = $this->unwrapped(
            '<figure><button type="button" class="lightbox-trigger">'
            . '<img src="https://x.test/a.jpg" width="992" height="558" alt="A"></button>'
            . '<figcaption>Caption.</figcaption></figure>',
        );

        self::assertStringNotContainsString('<button', $html);
        self::assertStringContainsString('<img src="https://x.test/a.jpg" width="992" height="558" alt="A">', $html);
        self::assertStringContainsString('<figcaption>Caption.</figcaption>', $html);
    }

    public function testPromotesAContentPictureOutOfItsButtonWrapper(): void
    {
        $html = $this->unwrapped(
            '<button><picture><img src="https://x.test/a.jpg" width="640" height="360" alt=""></picture></button>',
        );

        self::assertStringNotContainsString('<button', $html);
        self::assertStringContainsString('<picture>', $html);
        self::assertStringContainsString('<img src="https://x.test/a.jpg" width="640" height="360" alt="">', $html);
    }

    public function testLeavesAnIconImageButtonAlone(): void
    {
        $html = $this->unwrapped(
            '<button type="button" aria-label="Share">'
            . '<img src="https://x.test/share.svg" width="24" height="24" alt=""></button>',
        );

        self::assertStringContainsString('<button', $html);
    }

    public function testLeavesAButtonWhoseImageOnlyReachesTheIconCeiling(): void
    {
        // Exactly the ceiling on one edge is still icon-sized; the ceiling is exclusive.
        $html = $this->unwrapped(
            '<button><img src="https://x.test/a.jpg" width="100" height="200" alt=""></button>',
        );

        self::assertStringContainsString('<button', $html);
    }

    public function testLeavesAButtonWhoseImagePassesOnOnlyOneEdge(): void
    {
        // Both edges must clear the ceiling: a wide but short strip is not a photo.
        $html = $this->unwrapped(
            '<button><img src="https://x.test/a.jpg" width="640" height="80" alt=""></button>',
        );

        self::assertStringContainsString('<button', $html);
    }

    public function testLeavesAButtonWhoseWidthIsNotNumeric(): void
    {
        $html = $this->unwrapped(
            '<button><img src="https://x.test/a.jpg" width="auto" height="558" alt=""></button>',
        );

        self::assertStringContainsString('<button', $html);
    }

    public function testLeavesAButtonWhoseHeightIsNotNumeric(): void
    {
        $html = $this->unwrapped(
            '<button><img src="https://x.test/a.jpg" width="992" height="auto" alt=""></button>',
        );

        self::assertStringContainsString('<button', $html);
    }

    public function testPromotesAContentPhotoWhoseSourceSchemeIsUppercase(): void
    {
        $html = $this->unwrapped(
            '<button><img src="HTTPS://x.test/a.jpg" width="992" height="558" alt=""></button>',
        );

        self::assertStringNotContainsString('<button', $html);
    }

    public function testPromotesAContentPhotoWhoseSourceCarriesSurroundingWhitespace(): void
    {
        $html = $this->unwrapped(
            '<button><img src="  https://x.test/a.jpg  " width="992" height="558" alt=""></button>',
        );

        self::assertStringNotContainsString('<button', $html);
    }

    public function testLeavesAButtonWhoseSourceOnlyContainsAScheme(): void
    {
        // A proxy path that merely embeds a URL is not itself an absolute source.
        $html = $this->unwrapped(
            '<button><img src="/redirect?to=http://x.test/a.jpg" width="992" height="558" alt=""></button>',
        );

        self::assertStringContainsString('<button', $html);
    }

    public function testLeavesATrackingPixelButtonAlone(): void
    {
        $html = $this->unwrapped(
            '<button><img src="https://x.test/beacon.gif" width="1" height="1" alt=""></button>',
        );

        self::assertStringContainsString('<button', $html);
    }

    public function testLeavesAnUndimensionedImageButtonAlone(): void
    {
        $html = $this->unwrapped('<button><img src="https://x.test/icon.png" alt=""></button>');

        self::assertStringContainsString('<button', $html);
    }

    public function testLeavesAnImagelessButtonForReadabilityToDrop(): void
    {
        $html = $this->unwrapped('<button type="button" class="share">Share</button>');

        self::assertStringContainsString('<button', $html);
        self::assertStringContainsString('Share', $html);
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
