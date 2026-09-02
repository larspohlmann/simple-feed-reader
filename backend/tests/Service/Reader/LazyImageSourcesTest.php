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

    public function testDropsThePictureSourcesWhenTheImageCarriesItsOwnSource(): void
    {
        // NDR wraps each photo in a <picture> whose <source srcset> lists a 20w
        // placeholder first and sets sizes="1px"; its own script rewrites the
        // size after layout. The reader strips that script, so a surviving
        // <source> makes the browser pick the placeholder over the real <img>.
        $html = $this->resolvedHtml(
            '<picture>'
            . '<source type="image/webp" srcset="/assets/placeholder.png 20w,'
            . ' https://images.example.com/photo.webp?width=256 256w" sizes="1px">'
            . '<img src="https://images.example.com/photo.webp?width=576" alt="A">'
            . '</picture>'
        );

        self::assertStringNotContainsString('<source', $html);
        self::assertStringContainsString('https://images.example.com/photo.webp?width=576', $html);
    }

    public function testDropsThePictureSourcesAfterPromotingABareImage(): void
    {
        // The promoted src is authoritative, so the sibling <source> that fed it
        // must not remain to override the selection the reader just made.
        $html = $this->resolvedHtml(
            '<picture><source srcset="https://images.example.com/small.jpg 384w">'
            . '<img alt="A"></picture>'
        );

        self::assertStringNotContainsString('<source', $html);
        self::assertStringContainsString('src="https://images.example.com/small.jpg"', $html);
    }

    public function testAdoptsAWiderPictureSourceWhenTheImageSrcIsAPlaceholder(): void
    {
        // taz wraps each photo in a <picture> whose <source>s carry the real
        // renditions and whose <img> fallback src is a 14px LQIP placeholder
        // (entry 486683). The placeholder is a valid https URL, so it survives
        // the usable-source check; the wider <source> has to win regardless.
        $html = $this->resolvedHtml(
            '<picture>'
            . '<source srcset="https://cdn.example.com/picture/8227075/1020/x.webp">'
            . '<source srcset="https://cdn.example.com/picture/8227075/665/x.webp">'
            . '<source srcset="https://cdn.example.com/picture/8227075/310/x.webp">'
            . '<img src="https://cdn.example.com/picture/8227075/14/x.webp" height="206">'
            . '</picture>'
        );

        self::assertStringContainsString('src="https://cdn.example.com/picture/8227075/1020/x.webp"', $html);
        self::assertStringNotContainsString('/14/', $html);
        self::assertStringNotContainsString('<source', $html);
    }

    public function testAdoptsAPictureSourceThatOutmeasuresTheImageSrc(): void
    {
        // Both the <img> and the <source> declare a width, so the wider of the
        // two wins even though the <img> already carries a usable src.
        $source = $this->resolvedSource(
            '<picture>'
            . '<source srcset="https://images.example.com/photo.jpg?width=800 800w">'
            . '<img src="https://images.example.com/photo.jpg?width=200" alt="A">'
            . '</picture>'
        );

        self::assertSame('https://images.example.com/photo.jpg?width=800', $source);
    }

    public function testRemovesStaleDimensionsWhenAdoptingAPictureSource(): void
    {
        $image = $this->resolvedDocument(
            '<picture>'
            . '<source srcset="https://images.example.com/photo-1300.jpg 1300w">'
            . '<img src="https://images.example.com/photo-480.jpg" width="480" height="274">'
            . '</picture>'
        )->getElementsByTagName('img')->item(0);

        self::assertInstanceOf(\Dom\Element::class, $image);
        self::assertSame('https://images.example.com/photo-1300.jpg', $image->getAttribute('src'));
        self::assertNull($image->getAttribute('width'));
        self::assertNull($image->getAttribute('height'));
    }

    public function testKeepsDimensionsWhenThePictureSourceUsesTheSameUrl(): void
    {
        $image = $this->resolvedDocument(
            '<picture>'
            . '<source srcset="https://images.example.com/photo.jpg 1300w">'
            . '<img src="https://images.example.com/photo.jpg" width="1300" height="742">'
            . '</picture>'
        )->getElementsByTagName('img')->item(0);

        self::assertInstanceOf(\Dom\Element::class, $image);
        self::assertSame('1300', $image->getAttribute('width'));
        self::assertSame('742', $image->getAttribute('height'));
    }

    public function testKeepsTheImageWhenItsWidthEqualsTheWidestSource(): void
    {
        // Equal widths leave the <img> in place: the source is no improvement.
        $source = $this->resolvedSource(
            '<picture>'
            . '<source srcset="https://images.example.com/from-source.jpg?width=400 400w">'
            . '<img src="https://images.example.com/from-image.jpg?width=400" alt="A">'
            . '</picture>'
        );

        self::assertSame('https://images.example.com/from-image.jpg?width=400', $source);
    }

    public function testMeasuresAPictureSourceByItsDescriptorNotItsUrlQuery(): void
    {
        // The <source> descriptor says 300w though its URL query says 999. The
        // descriptor is authoritative, so the 500-wide <img> stays (500 >= 300).
        $source = $this->resolvedSource(
            '<picture>'
            . '<source srcset="https://images.example.com/from-source.jpg?width=999 300w">'
            . '<img src="https://images.example.com/from-image.jpg?width=500" alt="A">'
            . '</picture>'
        );

        self::assertSame('https://images.example.com/from-image.jpg?width=500', $source);
    }

    public function testSkipsUnusableSourcesWhenChoosingTheWidest(): void
    {
        // The first <source> carries no srcset and the second an unsafe scheme;
        // only the third is loadable and must beat the placeholder <img>.
        $source = $this->resolvedSource(
            '<picture>'
            . '<source>'
            . '<source srcset="javascript:alert(1)">'
            . '<source srcset="https://cdn.example.com/picture/1/1000/x.webp">'
            . '<img src="https://cdn.example.com/picture/1/14/x.webp">'
            . '</picture>'
        );

        self::assertSame('https://cdn.example.com/picture/1/1000/x.webp', $source);
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
    public function testKeepsACommaBearingTransformUrlWhenAdoptingAPictureSource(): void
    {
        // Substack wraps its images in a <picture> whose candidates are Cloudinary
        // transform URLs, and those spell their options with commas. A list split
        // on every comma leaves the tail as a relative URL (#706, entry 487639).
        $source = $this->resolvedSource(
            '<picture><source type="image/webp" srcset="'
            . 'https://cdn.example.com/fetch/$s_!-_9x!,w_424,c_limit,f_webp,fl_progressive:steep/photo.png 424w,'
            . ' https://cdn.example.com/fetch/$s_!-_9x!,w_1456,c_limit,f_webp,fl_progressive:steep/photo.png 1456w">'
            . '<img src="https://cdn.example.com/fetch/$s_!-_9x!,f_auto,fl_progressive:steep/photo.png"></picture>'
        );

        self::assertSame(
            'https://cdn.example.com/fetch/$s_!-_9x!,w_1456,c_limit,f_webp,fl_progressive:steep/photo.png',
            $source,
        );
    }

    /** nature.com 495343: a lazy <picture> carries its candidates on `data-srcset`, and its <img> has no src at all. */
    public function testPromotesTheLazySourceOfAPictureWhoseImageIsBare(): void
    {
        $source = $this->resolvedSource(
            '<picture data-lazy="true"><source data-srcset="./assets/a/photo-750x422.webp 750w,'
            . ' ./assets/a/photo-2560x1440.webp 2560w" type="image/webp"><img alt="A"></picture>'
        );

        self::assertSame('./assets/a/photo-750x422.webp', $source);
    }

    public function testPrefersAPictureSourcesLazyListOverItsPlaceholderList(): void
    {
        $source = $this->resolvedSource(
            '<picture><source srcset="https://images.example.com/blank.gif 20w"'
            . ' data-srcset="https://images.example.com/real.jpg 750w"><img alt="A"></picture>'
        );

        self::assertSame('https://images.example.com/real.jpg', $source);
    }

    public function testAdoptsAWiderLazyPictureSourceOverThePlaceholderImage(): void
    {
        $source = $this->resolvedSource(
            '<picture><source data-srcset="https://images.example.com/large.jpg 2000w">'
            . '<img src="https://images.example.com/small.jpg?w=300" alt="A"></picture>'
        );

        self::assertSame('https://images.example.com/large.jpg', $source);
    }

    public function testFlattensALazyPictureOncePromoted(): void
    {
        $html = $this->resolvedHtml(
            '<figure><picture data-lazy="true"><source data-srcset="https://images.example.com/photo.jpg 750w">'
            . '<img alt="A"></picture></figure>'
        );

        self::assertStringNotContainsString('<picture', $html);
        self::assertStringNotContainsString('<source', $html);
        self::assertStringContainsString('<img alt="A" src="https://images.example.com/photo.jpg">', $html);
    }

    public function testAnEmptyLazyAttributeFallsThroughToTheNextSourceAttribute(): void
    {
        $source = $this->resolvedSource(
            '<picture data-lazy="true"><source data-lazy-srcset="" '
            . 'data-srcset="https://images.example.com/real.jpg 750w"><img alt="A"></picture>'
        );

        self::assertSame('https://images.example.com/real.jpg', $source);
    }

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
