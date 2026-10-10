<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Entity\ImageRendition;
use App\Service\Parser\ItemImageExtractor;
use App\Service\Parser\Pass\CoreElement;
use PHPUnit\Framework\TestCase;

final class ItemImageExtractorTest extends TestCase
{
    private const ATOM_NAMESPACE = 'http://www.w3.org/2005/Atom';

    private ItemImageExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ItemImageExtractor();
    }

    private function item(string $innerXml): \DOMElement
    {
        $document = new \DOMDocument();
        /** @noinspection XmlUnusedNamespaceDeclaration */
        $rss = '<rss xmlns:media="http://search.yahoo.com/mrss/"><channel><item>'
            . $innerXml
            . '</item></channel></rss>';
        $document->loadXML($rss);
        $item = $document->getElementsByTagName('item')->item(0);
        self::assertInstanceOf(\DOMElement::class, $item);

        return $item;
    }

    public function testPicksTheWidestMediaContentVariant(): void
    {
        $image = $this->extractor->fromMedia($this->item(
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
        $image = $this->extractor->fromMedia($this->item(
            '<media:thumbnail url="https://i/t.jpg" width="948" height="474"/>',
        ));

        self::assertNotNull($image);
        self::assertSame(948, $image->width);
        self::assertSame(474, $image->height);
    }

    public function testAWiderContentBeatsAThumbnail(): void
    {
        $image = $this->extractor->fromMedia($this->item(
            '<media:thumbnail url="https://i/t.jpg" width="240" height="135"/>'
            . '<media:content url="https://i/c.jpg" medium="image" width="2400"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/c.jpg', $image->url);
    }

    public function testAnUndeclaredWidthLosesToADeclaredOne(): void
    {
        $image = $this->extractor->fromMedia($this->item(
            '<media:content url="https://i/unknown.jpg" medium="image"/>'
            . '<media:content url="https://i/known.jpg" medium="image" width="300"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/known.jpg', $image->url);
    }

    public function testAcceptsAMediaContentDeclaredByTypeInsteadOfMedium(): void
    {
        $image = $this->extractor->fromMedia($this->item(
            '<media:content url="https://i/typed.png" type="image/png" width="300"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/typed.png', $image->url);
    }

    public function testIgnoresAMediaContentWithNeitherMediumNorTypeDeclared(): void
    {
        self::assertNull($this->extractor->fromMedia($this->item(
            '<media:content url="https://i/episode.mp3" length="583910"/>',
        )));
    }

    public function testIgnoresAMediaContentWithAnExplicitNonImageKind(): void
    {
        self::assertNull($this->extractor->fromMedia($this->item(
            '<media:content url="https://i/episode.mp3" medium="audio"/>'
            . '<media:content url="https://i/clip.mp4" type="video/mp4"/>',
        )));
    }

    /**
     * A bare <media:content> with no medium or type, its image extension before the query string: the live shape
     * extension inference exists for, whose absence left a whole feed without images (#148).
     */
    public function testSelectsAWidestBareMediaContentByImageExtension(): void
    {
        $image = $this->extractor->fromMedia($this->item(
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
        $image = $this->extractor->fromMedia($this->item(
            '<media:content url="https://i/first.jpg" medium="image"/>'
            . '<media:content url="https://i/second.jpg" medium="image"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/first.jpg', $image->url);
    }

    public function testSearchesInsideAMediaGroup(): void
    {
        $image = $this->extractor->fromMedia($this->item(
            '<media:group><media:content url="https://i/g.jpg" medium="image" width="500"/></media:group>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/g.jpg', $image->url);
    }

    public function testADirectImageCompetesWithTheImagesInAMediaGroup(): void
    {
        $image = $this->extractor->fromMedia($this->item(
            '<media:content url="https://i/direct.jpg" medium="image" width="900"/>'
            . '<media:group><media:content url="https://i/g.jpg" medium="image" width="500"/></media:group>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/direct.jpg', $image->url);
    }

    public function testReadsAnRssEnclosure(): void
    {
        $image = $this->extractor->fromRssEnclosure($this->item(
            '<enclosure url="https://i/e.jpg" type="image/jpeg" length="0"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/e.jpg', $image->url);
        self::assertNull($image->width);
    }

    public function testIgnoresANonImageEnclosure(): void
    {
        self::assertNull($this->extractor->fromRssEnclosure($this->item(
            '<enclosure url="https://i/a.mp3" type="audio/mpeg" length="10"/>',
        )));
    }

    public function testReadsAnInlineImgWithoutDimensions(): void
    {
        $image = $this->extractor->fromHtml('<p>x</p><img src="https://i/inline.jpg" alt="">');

        self::assertNotNull($image);
        self::assertSame('https://i/inline.jpg', $image->url);
        self::assertNull($image->width);
    }

    public function testTrimsSurroundingWhitespaceFromAnInlineImgSrc(): void
    {
        $image = $this->extractor->fromHtml('<img src="  https://i/inline.jpg  ">');

        self::assertNotNull($image);
        self::assertSame('https://i/inline.jpg', $image->url);
    }

    public function testAWhitespaceOnlyInlineImgSrcIsTreatedAsMissing(): void
    {
        self::assertNull($this->extractor->fromHtml('<img src="   ">'));
    }

    public function testReadsACustomImageElementWithItsDeclaredDimensions(): void
    {
        $image = $this->extractor->fromCustomImageElement($this->item(
            '<image url="https://images.utopia.de/x/w:194/h:126/pic.jpg" width="194" height="126"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://images.utopia.de/x/w:194/h:126/pic.jpg', $image->url);
        self::assertSame(194, $image->width);
        self::assertSame(126, $image->height);
    }

    public function testPrefersTheLargerImageBigVariantOverImage(): void
    {
        $image = $this->extractor->fromCustomImageElement($this->item(
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
        $image = $this->extractor->fromCustomImageElement($this->item(
            '<image url="https://images.utopia.de/x/w:194/h:126/only.jpg" width="194" height="126"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://images.utopia.de/x/w:194/h:126/only.jpg', $image->url);
    }

    public function testIgnoresAStandardImageElementThatNestsAUrlChild(): void
    {
        self::assertNull($this->extractor->fromCustomImageElement($this->item(
            '<image><url>https://example.com/logo.png</url><title>Logo</title></image>',
        )));
    }

    public function testYieldsNothingWhenNoCustomImageElementIsPresent(): void
    {
        self::assertNull($this->extractor->fromCustomImageElement($this->item(
            '<description>No picture here.</description>',
        )));
    }

    public function testIgnoresAUrlBearingElementThatIsNotACustomImageElement(): void
    {
        self::assertNull($this->extractor->fromCustomImageElement($this->item(
            '<enclosure url="https://i/e.jpg" type="image/jpeg"/>',
        )));
    }

    public function testTrimsSurroundingWhitespaceFromACustomImageUrl(): void
    {
        $image = $this->extractor->fromCustomImageElement($this->item(
            '<image_big url="  https://i/padded.jpg  " width="640"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/padded.jpg', $image->url);
    }

    public function testPicksTheWidestAmongSeveralImageBigVariants(): void
    {
        $image = $this->extractor->fromCustomImageElement($this->item(
            '<image_big url="https://i/narrow.jpg" width="200"/>'
            . '<image_big url="https://i/wide.jpg" width="800"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/wide.jpg', $image->url);
        self::assertSame(800, $image->width);
    }

    public function testReadsAnInlineImgWithAnUnquotedSrc(): void
    {
        $image = $this->extractor->fromHtml('<p>x</p><img width=287 height=107 src=https://i/webp.webp>');

        self::assertNotNull($image);
        self::assertSame('https://i/webp.webp', $image->url);
        self::assertSame(287, $image->width);
        self::assertSame(107, $image->height);
    }

    public function testCapturesDeclaredDimensionsFromAnInlineImg(): void
    {
        $image = $this->extractor->fromHtml('<img src="https://i/a.jpg" width="640" height="360">');

        self::assertNotNull($image);
        self::assertSame(640, $image->width);
        self::assertSame(360, $image->height);
    }

    public function testIgnoresNonIntegerDimensionAttributes(): void
    {
        $image = $this->extractor->fromHtml('<img src="https://i/a.jpg" width="100%" height="auto">');

        self::assertNotNull($image);
        self::assertNull($image->width);
        self::assertNull($image->height);
    }

    public function testSkipsALeadingImgWithoutASrcAndTakesTheNext(): void
    {
        $image = $this->extractor->fromHtml('<img alt="spacer"><img src="https://i/real.jpg">');

        self::assertNotNull($image);
        self::assertSame('https://i/real.jpg', $image->url);
    }

    public function testReturnsNullWhenTheHtmlHasNoImg(): void
    {
        self::assertNull($this->extractor->fromHtml('<p>just words</p>'));
    }

    public function testReturnsNullWithoutHtml(): void
    {
        self::assertNull($this->extractor->fromHtml(null));
    }

    public function testFindsAnUpperCaseImgTag(): void
    {
        $image = $this->extractor->fromHtml('<P>x</P><IMG SRC="https://i/shouted.jpg">');

        self::assertSame('https://i/shouted.jpg', $image?->url);
    }

    public function testSkipsADeclaredBeaconAndTakesTheNextImage(): void
    {
        $image = $this->extractor->fromHtml(
            '<img src="https://pixel.wp.com/b.gif" width="1" height="1">'
            . '<img src="https://i/real.jpg" width="800" height="450">',
        );

        self::assertNotNull($image);
        self::assertSame('https://i/real.jpg', $image->url);
        self::assertSame(800, $image->width);
        self::assertSame(450, $image->height);
    }

    public function testADeclaredBeaconAloneYieldsNoImage(): void
    {
        self::assertNull($this->extractor->fromHtml(
            '<img src="https://pixel.wp.com/b.gif" width="1" height="1">',
        ));
    }

    public function testAnImageWithOneSmallDeclaredEdgeIsKept(): void
    {
        $image = $this->extractor->fromHtml(
            '<img src="http://www.techmeme.com/x/i1.jpg" width="134" height="76">',
        );

        self::assertNotNull($image);
        self::assertSame('http://www.techmeme.com/x/i1.jpg', $image->url);
    }

    public function testAnAtomEnclosureLinkYieldsItsImage(): void
    {
        $image = $this->extractor->fromAtomEnclosure(
            $this->atomEntry('<link rel="enclosure" type="image/png" href="https://i/enc.png"/>'),
        );

        self::assertSame('https://i/enc.png', $image?->url);
    }

    public function testAnAtomEnclosureLinkMatchesItsTypeCaseInsensitivelyAndTrimsItsHref(): void
    {
        $image = $this->extractor->fromAtomEnclosure(
            $this->atomEntry('<link rel="enclosure" type="IMAGE/PNG" href="  https://i/enc.png  "/>'),
        );

        self::assertSame('https://i/enc.png', $image?->url);
    }

    public function testAnAtomLinkThatIsNotAnEnclosureYieldsNoImage(): void
    {
        self::assertNull($this->extractor->fromAtomEnclosure(
            $this->atomEntry('<link rel="alternate" type="image/png" href="https://i/alt.png"/>'),
        ));
    }

    public function testAnEnclosureLinkOutsideTheAtomNamespaceYieldsNoImage(): void
    {
        self::assertNull($this->extractor->fromAtomEnclosure(
            $this->atomEntry('<x:link xmlns:x="urn:other" rel="enclosure" type="image/png" href="https://i/x.png"/>'),
        ));
    }

    public function testAnEnclosureElementThatIsNotALinkYieldsNoImage(): void
    {
        self::assertNull($this->extractor->fromAtomEnclosure(
            $this->atomEntry('<content rel="enclosure" type="image/png" href="https://i/c.png"/>'),
        ));
    }

    private function atomEntry(string $innerXml): CoreElement
    {
        $document = new \DOMDocument();
        $document->loadXML('<feed xmlns="' . self::ATOM_NAMESPACE . '"><entry>' . $innerXml . '</entry></feed>');
        $entry = $document->getElementsByTagName('entry')->item(0);
        self::assertInstanceOf(\DOMElement::class, $entry);

        return new CoreElement($entry, self::ATOM_NAMESPACE);
    }

    public function testReadsTheWidthDescribedSrcsetOfABodyImage(): void
    {
        $image = $this->extractor->fromHtml(
            '<img width="696" height="464" src="https://mag.example/funk-system-1024x683.jpg"'
            . ' srcset="https://mag.example/funk-system-1024x683.jpg 1024w,'
            . ' https://mag.example/funk-system-300x200.jpg 300w,'
            . ' https://mag.example/funk-system-1536x1024.jpg 1536w">',
        );

        self::assertNotNull($image);
        self::assertSame('https://mag.example/funk-system-1024x683.jpg', $image->url);
        self::assertEquals(
            [
                new ImageRendition('https://mag.example/funk-system-1024x683.jpg', 1024),
                new ImageRendition('https://mag.example/funk-system-300x200.jpg', 300),
                new ImageRendition('https://mag.example/funk-system-1536x1024.jpg', 1536),
            ],
            $image->renditions,
        );
        self::assertSame(696, $image->width);
    }

    public function testTheWidthAttributeIsNoRenditionBesideAWidthDescribedSrcset(): void
    {
        $image = $this->extractor->fromHtml(
            '<img src="https://i/photo.jpg" width="300"'
            . ' srcset="https://i/photo-768x512.jpg 768w, https://i/photo-1024x683.jpg 1024w">',
        );

        self::assertNotNull($image);
        self::assertEquals(
            [
                new ImageRendition('https://i/photo-768x512.jpg', 768),
                new ImageRendition('https://i/photo-1024x683.jpg', 1024),
            ],
            $image->renditions,
        );
    }

    public function testTheWidthAttributeIsTheRenditionBesideADensityOnlySrcset(): void
    {
        $image = $this->extractor->fromHtml(
            '<img src="https://i/photo.jpg" width="300" srcset="https://i/photo-2x.jpg 2x">',
        );

        self::assertNotNull($image);
        self::assertEquals([new ImageRendition('https://i/photo.jpg', 300)], $image->renditions);
    }

    public function testIgnoresDensityBareAndMalformedSrcsetCandidates(): void
    {
        $image = $this->extractor->fromHtml(
            '<img src="https://i/a.jpg" srcset="https://i/a-2x.jpg 2x, https://i/bare.jpg,'
            . ' https://i/zero.jpg 0w, https://i/wide.jpg 800wide, https://i/a-640.jpg 640w">',
        );

        self::assertNotNull($image);
        self::assertEquals([new ImageRendition('https://i/a-640.jpg', 640)], $image->renditions);
    }

    public function testAnInlineImgsDeclaredWidthIsItsOwnRendition(): void
    {
        $image = $this->extractor->fromHtml('<img src="https://i/a.jpg" width="640" height="360">');

        self::assertNotNull($image);
        self::assertEquals([new ImageRendition('https://i/a.jpg', 640)], $image->renditions);
    }

    public function testAnInlineImgWithoutWidthOrSrcsetHasNoRenditions(): void
    {
        $image = $this->extractor->fromHtml('<img src="https://i/a.jpg" height="360">');

        self::assertNotNull($image);
        self::assertSame([], $image->renditions);
    }

    public function testAnEnclosureWithADeclaredWidthIsItsOwnRendition(): void
    {
        $image = $this->extractor->fromRssEnclosure(
            $this->item('<enclosure url="https://i/e.jpg" type="image/jpeg" width="1200"/>'),
        );

        self::assertNotNull($image);
        self::assertEquals([new ImageRendition('https://i/e.jpg', 1200)], $image->renditions);
    }

    public function testAMediaVariantWithoutAWidthHasNoRendition(): void
    {
        $image = $this->extractor->fromMedia($this->item('<media:content url="https://i/a.jpg" medium="image"/>'));

        self::assertNotNull($image);
        self::assertSame([], $image->renditions);
    }

    public function testCollectsEveryWidthOfTheWidestMediaPicture(): void
    {
        $photo = 'https://i.guim.co.uk/img/media/f6d33de551f7fcdc046178cfccc4037e79b99f3e'
            . '/276_0_4639_3711/master/4639.jpg';
        $image = $this->extractor->fromMedia($this->item(
            '<media:content width="140" url="' . $photo . '?width=140&amp;s=406198660"/>'
            . '<media:content width="460" url="' . $photo . '?width=460&amp;s=fed507e2"/>'
            . '<media:content width="700" url="' . $photo . '?width=700&amp;s=6192bfa4"/>',
        ));

        self::assertNotNull($image);
        self::assertSame($photo . '?width=700&s=6192bfa4', $image->url);
        self::assertEquals(
            [
                new ImageRendition($photo . '?width=700&s=6192bfa4', 700),
                new ImageRendition($photo . '?width=140&s=406198660', 140),
                new ImageRendition($photo . '?width=460&s=fed507e2', 460),
            ],
            $image->renditions,
        );
    }

    public function testKeepsAnotherPictureOfAMediaGalleryOutOfTheLadder(): void
    {
        $uploads = 'https://kursfahrradstadt.de/wp-content/uploads/2026/05/';
        $image = $this->extractor->fromMedia($this->item(
            '<media:content url="' . $uploads . 'superbuettel-eroeffnung-relli-festtag-21.jpg" medium="image"'
            . ' width="1200"/>'
            . '<media:content url="' . $uploads . 'superbuettel-eroeffnung-relli-festtag-33.jpg" medium="image"'
            . ' width="800"/>',
        ));

        self::assertNotNull($image);
        self::assertEquals(
            [new ImageRendition($uploads . 'superbuettel-eroeffnung-relli-festtag-21.jpg', 1200)],
            $image->renditions,
        );
    }

    public function testKeepsASquareThumbnailCropOutOfTheLadder(): void
    {
        $uploads = 'https://cdn.arstechnica.net/wp-content/uploads/2026/09/';
        $image = $this->extractor->fromMedia($this->item(
            '<media:content height="648" medium="image" url="' . $uploads . 'GettyImages-1042124682-1152x648.jpg"'
            . ' width="1152"/>'
            . '<media:thumbnail height="500" url="' . $uploads . 'GettyImages-1042124682-500x500.jpg" width="500"/>',
        ));

        self::assertNotNull($image);
        self::assertEquals(
            [new ImageRendition($uploads . 'GettyImages-1042124682-1152x648.jpg', 1152)],
            $image->renditions,
        );
    }
}
