<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\ItemImageExtractor;
use PHPUnit\Framework\TestCase;

final class ItemImageExtractorTest extends TestCase
{
    private function item(string $innerXml): \DOMElement
    {
        $doc = new \DOMDocument();
        // @lang TEXT: the `media` prefix is used by the `$innerXml` the callers
        // splice in, which the XML PhpStorm injects here cannot see, so it
        // reports the namespace declaration as unused.
        /** @noinspection XmlUnusedNamespaceDeclaration */
        $rss = '<rss xmlns:media="http://search.yahoo.com/mrss/"><channel><item>'
            . $innerXml
            . '</item></channel></rss>';
        $doc->loadXML($rss);
        $item = $doc->getElementsByTagName('item')->item(0);
        self::assertInstanceOf(\DOMElement::class, $item);

        return $item;
    }

    public function testPicksTheWidestMediaContentVariant(): void
    {
        $image = ItemImageExtractor::fromMedia($this->item(
            '<media:content url="https://i/small.jpg" medium="image" width="140"/>'
            . '<media:content url="https://i/mid.jpg" medium="image" width="460"/>'
            . '<media:content url="https://i/big.jpg" medium="image" width="700"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/big.jpg', $image->url);
        self::assertSame(700, $image->width);
        self::assertNull($image->height);
    }

    public function testCapturesBothDeclaredDimensions(): void
    {
        $image = ItemImageExtractor::fromMedia($this->item(
            '<media:thumbnail url="https://i/t.jpg" width="948" height="474"/>',
        ));

        self::assertNotNull($image);
        self::assertSame(948, $image->width);
        self::assertSame(474, $image->height);
    }

    public function testAWiderContentBeatsAThumbnail(): void
    {
        $image = ItemImageExtractor::fromMedia($this->item(
            '<media:thumbnail url="https://i/t.jpg" width="240" height="135"/>'
            . '<media:content url="https://i/c.jpg" medium="image" width="2400"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/c.jpg', $image->url);
    }

    public function testAnUndeclaredWidthLosesToADeclaredOne(): void
    {
        $image = ItemImageExtractor::fromMedia($this->item(
            '<media:content url="https://i/unknown.jpg" medium="image"/>'
            . '<media:content url="https://i/known.jpg" medium="image" width="300"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/known.jpg', $image->url);
    }

    public function testAcceptsAMediaContentDeclaredByTypeInsteadOfMedium(): void
    {
        $image = ItemImageExtractor::fromMedia($this->item(
            '<media:content url="https://i/typed.png" type="image/png" width="300"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/typed.png', $image->url);
    }

    public function testIgnoresAMediaContentWithNeitherMediumNorTypeDeclared(): void
    {
        self::assertNull(ItemImageExtractor::fromMedia($this->item(
            '<media:content url="https://i/episode.mp3" length="583910"/>',
        )));
    }

    public function testIgnoresAMediaContentWithAnExplicitNonImageKind(): void
    {
        self::assertNull(ItemImageExtractor::fromMedia($this->item(
            '<media:content url="https://i/episode.mp3" medium="audio"/>'
            . '<media:content url="https://i/clip.mp4" type="video/mp4"/>',
        )));
    }

    /**
     * The Guardian's real format: a bare <media:content> with width first and no
     * medium or type at all, the URL carrying an image extension before its query
     * string. Every other test declares medium="image", which the live feed does
     * not — this is the case that the extension inference exists for, and whose
     * absence let the whole feed regress to zero images (#148).
     */
    public function testSelectsAWidestBareMediaContentByImageExtension(): void
    {
        $image = ItemImageExtractor::fromMedia($this->item(
            '<media:content width="140" url="https://i.guim.co.uk/img/media/x/master/4299.jpg?width=140&amp;s=a"/>'
            . '<media:content width="460" url="https://i.guim.co.uk/img/media/x/master/4299.jpg?width=460&amp;s=b"/>'
            . '<media:content width="700" url="https://i.guim.co.uk/img/media/x/master/4299.jpg?width=700&amp;s=c"/>',
        ));

        self::assertNotNull($image);
        self::assertSame(700, $image->width);
        self::assertStringContainsString('width=700', $image->url);
    }

    public function testFallsBackToDocumentOrderWhenNothingDeclaresAWidth(): void
    {
        $image = ItemImageExtractor::fromMedia($this->item(
            '<media:content url="https://i/first.jpg" medium="image"/>'
            . '<media:content url="https://i/second.jpg" medium="image"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/first.jpg', $image->url);
    }

    public function testSearchesInsideAMediaGroup(): void
    {
        $image = ItemImageExtractor::fromMedia($this->item(
            '<media:group><media:content url="https://i/g.jpg" medium="image" width="500"/></media:group>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/g.jpg', $image->url);
    }

    public function testReadsAnRssEnclosure(): void
    {
        $image = ItemImageExtractor::fromRssEnclosure($this->item(
            '<enclosure url="https://i/e.jpg" type="image/jpeg" length="0"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/e.jpg', $image->url);
        self::assertNull($image->width);
    }

    public function testIgnoresANonImageEnclosure(): void
    {
        self::assertNull(ItemImageExtractor::fromRssEnclosure($this->item(
            '<enclosure url="https://i/a.mp3" type="audio/mpeg" length="10"/>',
        )));
    }

    public function testReadsAnInlineImgWithoutDimensions(): void
    {
        $image = ItemImageExtractor::fromHtml('<p>x</p><img src="https://i/inline.jpg" alt="">');

        self::assertNotNull($image);
        self::assertSame('https://i/inline.jpg', $image->url);
        self::assertNull($image->width);
    }

    public function testReadsACustomImageElementWithItsDeclaredDimensions(): void
    {
        $image = ItemImageExtractor::fromCustomImageElement($this->item(
            '<image url="https://images.utopia.de/x/w:194/h:126/pic.jpg" width="194" height="126"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://images.utopia.de/x/w:194/h:126/pic.jpg', $image->url);
        self::assertSame(194, $image->width);
        self::assertSame(126, $image->height);
    }

    public function testPrefersTheLargerImageBigVariantOverImage(): void
    {
        $image = ItemImageExtractor::fromCustomImageElement($this->item(
            '<image url="https://images.utopia.de/x/w:194/h:126/small.jpg" width="194" height="126"/>'
            . '<image_big url="https://images.utopia.de/x/w:640/h:300/big.jpg" width="640" height="300"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://images.utopia.de/x/w:640/h:300/big.jpg', $image->url);
        self::assertSame(640, $image->width);
        self::assertSame(300, $image->height);
    }

    public function testFallsBackToImageWhenNoImageBigIsPresent(): void
    {
        $image = ItemImageExtractor::fromCustomImageElement($this->item(
            '<image url="https://images.utopia.de/x/w:194/h:126/only.jpg" width="194" height="126"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://images.utopia.de/x/w:194/h:126/only.jpg', $image->url);
    }

    /**
     * The standard channel-level <image> nests a <url> child element rather
     * than declaring a `url` attribute. Requiring the attribute keeps this
     * source from colliding with it, so an item carrying that shape yields
     * nothing here.
     */
    public function testIgnoresAStandardImageElementThatNestsAUrlChild(): void
    {
        self::assertNull(ItemImageExtractor::fromCustomImageElement($this->item(
            '<image><url>https://example.com/logo.png</url><title>Logo</title></image>',
        )));
    }

    public function testYieldsNothingWhenNoCustomImageElementIsPresent(): void
    {
        self::assertNull(ItemImageExtractor::fromCustomImageElement($this->item(
            '<description>No picture here.</description>',
        )));
    }

    /**
     * A url-bearing element that is not <image>/<image_big> — here an
     * <enclosure> — must not be mistaken for a custom image element.
     */
    public function testIgnoresAUrlBearingElementThatIsNotACustomImageElement(): void
    {
        self::assertNull(ItemImageExtractor::fromCustomImageElement($this->item(
            '<enclosure url="https://i/e.jpg" type="image/jpeg"/>',
        )));
    }

    public function testTrimsSurroundingWhitespaceFromACustomImageUrl(): void
    {
        $image = ItemImageExtractor::fromCustomImageElement($this->item(
            '<image_big url="  https://i/padded.jpg  " width="640"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/padded.jpg', $image->url);
    }

    public function testPicksTheWidestAmongSeveralImageBigVariants(): void
    {
        $image = ItemImageExtractor::fromCustomImageElement($this->item(
            '<image_big url="https://i/narrow.jpg" width="200"/>'
            . '<image_big url="https://i/wide.jpg" width="800"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/wide.jpg', $image->url);
        self::assertSame(800, $image->width);
    }
}
