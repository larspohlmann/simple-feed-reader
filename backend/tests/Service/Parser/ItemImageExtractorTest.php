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
        $doc->loadXML(
            '<rss xmlns:media="http://search.yahoo.com/mrss/"><channel><item>'
            . $innerXml
            . '</item></channel></rss>',
        );
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
}
