<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\ImageIdentity;
use PHPUnit\Framework\TestCase;

final class ImageIdentityTest extends TestCase
{
    private function sameImage(string $a, string $b): bool
    {
        return ImageIdentity::fromUrl($a)->matches(ImageIdentity::fromUrl($b));
    }

    public function testMatchesTheSamePhotoAcrossFormatAndSizeVariants(): void
    {
        // mopo: the og:image and the header <img> are the same photo, served as a
        // .jpg and a .webp at different widths but carrying the same imageId.
        self::assertTrue($this->sameImage(
            'https://image.mopo.de/4958348.jpg?imageId=4958348&width=1200&height=683',
            'https://image.mopo.de/4958348.webp?imageId=4958348&width=960&height=548&format=jpg',
        ));
    }

    public function testMatchesOnTheImageIdWhenTheFilenamesDiffer(): void
    {
        // The id ties two renders whose filenames share nothing else.
        self::assertTrue($this->sameImage(
            'https://cdn.test/a.jpg?imageId=zx9k2',
            'https://cdn.test/b.webp?imageId=zx9k2',
        ));
    }

    public function testMatchesOnAFiveCharacterToken(): void
    {
        // Five characters is the shortest token that carries identity; a shared
        // `polar` must tie the two crops even when nothing else does.
        self::assertTrue($this->sameImage(
            'https://cdn.test/x/polar-1.jpg',
            'https://cdn.test/y/polar-2.jpg',
        ));
    }

    public function testMatchesOnTheFilenameStemAlone(): void
    {
        self::assertTrue($this->sameImage(
            'https://cdn.test/a/elysia_channex_studio.jpg?itok=RCRJb73k',
            'https://cdn.test/b/elysia_channex_studio.jpg?itok=xxxx',
        ));
    }

    public function testMatchesOnASharedSignificantToken(): void
    {
        // nature crops the same asset under differing trailing numbers; the stable
        // article token is shared.
        self::assertTrue($this->sameImage(
            'https://media.nature.com/lw767/magazine-assets/d41586-026-02713-z/d41586-026-02713-z_53174408.jpg',
            'https://media.nature.com/w767/magazine-assets/d41586-026-02713-z/d41586-026-02713-z_53174412.jpg',
        ));
    }

    public function testSameAssetRejectsACommonPrefixWithDifferentAssetTokens(): void
    {
        $lead = ImageIdentity::fromUrl('https://cdn.test/d41586-026-02684-1_53170876.jpg');
        $related = ImageIdentity::fromUrl('https://cdn.test/d41586-026-02684-1_52157812.jpg');

        self::assertFalse($lead->isSameAsset($related));
    }

    public function testSameAssetRejectsAPublisherPrefixWithDifferentAssetTokens(): void
    {
        $lead = ImageIdentity::fromUrl('https://cdn.test/d41586-026-02240-x_52988140.jpg');
        $related = ImageIdentity::fromUrl('https://cdn.test/d41586-026-02733-9_53176100.jpg');

        self::assertFalse($lead->isSameAsset($related));
    }

    public function testSameAssetAcceptsAppendedSizeTokens(): void
    {
        $original = ImageIdentity::fromUrl('https://cdn.test/vegane-burrata-photo.jpg');
        $sized = ImageIdentity::fromUrl('https://cdn.test/vegane-burrata-photo-1280x854.jpg');

        self::assertTrue($original->isSameAsset($sized));
    }

    public function testSameAssetAcceptsAnExplicitImageId(): void
    {
        $jpeg = ImageIdentity::fromUrl('https://cdn.test/a.jpg?imageId=zx9k2');
        $webp = ImageIdentity::fromUrl('https://cdn.test/b.webp?imageId=zx9k2');

        self::assertTrue($jpeg->isSameAsset($webp));
    }

    public function testSameAssetRejectsUnrelatedTokens(): void
    {
        $balloon = ImageIdentity::fromUrl('https://cdn.test/red-balloon.jpg');
        $whale = ImageIdentity::fromUrl('https://cdn.test/blue-whale.jpg');

        self::assertFalse($balloon->isSameAsset($whale));
    }

    public function testSameAssetAcceptsTheSameNumericAssetToken(): void
    {
        $first = ImageIdentity::fromUrl('https://cdn.test/first-article_53170876.jpg');
        $second = ImageIdentity::fromUrl('https://cdn.test/second-article_53170876.jpg');

        self::assertTrue($first->isSameAsset($second));
    }

    public function testSameAssetKeepsNumericAndSizeSuffixesDistinct(): void
    {
        $asset = ImageIdentity::fromUrl('https://cdn.test/article-prefix_53170876.jpg');
        $size = ImageIdentity::fromUrl('https://cdn.test/article-prefix_1280x720.jpg');

        self::assertTrue($asset->isSameAsset($size));
        self::assertTrue($size->isSameAsset($asset));
    }

    public function testSameAssetRejectsDifferentFiveDigitAssetTokens(): void
    {
        $first = ImageIdentity::fromUrl('https://cdn.test/article-prefix_12345.jpg');
        $second = ImageIdentity::fromUrl('https://cdn.test/article-prefix_67890.jpg');

        self::assertFalse($first->isSameAsset($second));
    }

    public function testDoesNotMatchTwoUnrelatedRendersOfTheSamePhoto(): void
    {
        // beat.de: the opengraph share-render and the article's own upload are
        // genuinely different files. Identity cannot — and must not — link them.
        self::assertFalse($this->sameImage(
            'https://www.beat.de/media/tec_frontend_opengraph/2026/08/24/image-77437--34514.jpg?itok=RCRJb73k',
            'https://www.beat.de/media/tec_frontend_large/2026/08/24/elysia_channex_studio.jpg?itok=4Xr4wcbZ',
        ));
    }

    public function testDoesNotMatchTwoCropsThatShareOnlyAGenericCropName(): void
    {
        // zeit: every image in an article sits under one image-group directory and
        // differs only by a generic crop name. Two crops carry no shared distinct
        // token, so the basename cannot tell them apart — the blind spot is
        // deliberate: a miss leaves today's behaviour, it never fabricates a match.
        self::assertFalse($this->sameImage(
            'https://img.zeit.de/news/2026-08/28/a-image-group/wide__1300x731',
            'https://img.zeit.de/news/2026-08/28/a-image-group/wide__1280x720',
        ));
    }

    public function testMatchesAShortNumericStemThatCarriesNoToken(): void
    {
        // A basename whose only word is under five characters yields no token and
        // no imageId, so the stem itself is the only signal that two size/format
        // variants are the same photo.
        self::assertTrue($this->sameImage(
            'https://cdn.test/a/1234.jpg?w=800',
            'https://cdn.test/b/1234.webp?w=400',
        ));
        self::assertFalse($this->sameImage(
            'https://cdn.test/a/1234.jpg',
            'https://cdn.test/a/5678.jpg',
        ));
    }

    public function testDoesNotMatchDifferentPhotosOnTheSameHost(): void
    {
        self::assertFalse($this->sameImage(
            'https://image.mopo.de/4958348.jpg?imageId=4958348',
            'https://image.mopo.de/4958605.jpg?imageId=4958605',
        ));
    }

    /** imgproxy's spelling: the source URL, url-safe base64, as the last path segment. */
    private function imgproxy(string $source): string
    {
        $blob = rtrim(strtr(base64_encode($source), '+/', '-_'), '=');

        return "https://imgproxy.gridwork.co/sig/w:900/h:599/q:82/{$blob}.jpg";
    }

    public function testMatchesThroughAnImgproxyBase64Wrapper(): void
    {
        // inthesetimes (#686): the og:image is the direct S3 file; the body serves
        // the same photo through imgproxy, which base64-encodes the source URL in
        // the path. Without unwrapping, the fingerprint reads the opaque blob.
        $direct = 'https://s3.us-east-1.amazonaws.com/in-these-times/GettyImages-2272241474_1.jpg';
        self::assertTrue($this->sameImage(
            $direct . '?mtime=1787252863',
            $this->imgproxy($direct),
        ));
    }

    public function testMatchesThroughAUrlQueryParamProxy(): void
    {
        // politico (#686): both the lead and the body image are the same static
        // file, wrapped by the dims4 proxy at different sizes, source in `?url=`.
        $source = 'https://static.politico.com/b4/a9/4e9bfb8144bca5e8/election-2-26-michigan-9499.jpg';
        $proxy = static fn (int $width, string $format): string =>
            "https://www.politico.com/dims4/default/resize/{$width}/format/{$format}?url=" . rawurlencode($source);
        self::assertTrue($this->sameImage($proxy(1200, 'jpg'), $proxy(630, 'webp')));
    }

    public function testUnwrapsAProxyWhoseEmbeddedSchemeIsUppercase(): void
    {
        // URL schemes are case-insensitive; a proxy may embed HTTPS in upper case.
        $source = 'https://cdn.test/harbor-lighthouse.jpg';
        self::assertTrue($this->sameImage(
            $source,
            'https://proxy.test/x?url=' . rawurlencode('HTTPS://cdn.test/harbor-lighthouse.jpg'),
        ));
    }

    public function testPrefersTheQueryParamSourceOverAPathSegment(): void
    {
        // A URL can carry both spellings; the explicit `?url=` source is
        // authoritative and the base64 path segment is not consulted.
        $both = $this->imgproxy('https://cdn.test/path-loser.jpg')
            . '?url=' . rawurlencode('https://cdn.test/query-winner.jpg');
        self::assertTrue($this->sameImage('https://cdn.test/query-winner.jpg', $both));
        self::assertFalse($this->sameImage('https://cdn.test/path-loser.jpg', $both));
    }

    public function testMatchesThroughAJetpackPhotonHost(): void
    {
        self::assertTrue($this->sameImage(
            'https://i0.wp.com/example.org/wp-content/uploads/2026/08/harbor-sunrise.jpg?resize=1200%2C800',
            'https://example.org/wp-content/uploads/2026/08/harbor-sunrise.jpg',
        ));
    }

    public function testDoesNotMatchDifferentPhotosWrappedByTheSameProxy(): void
    {
        // Two distinct photos behind imgproxy must still read as different: unwrap
        // recovers precision, it never collapses unrelated sources into one.
        self::assertFalse($this->sameImage(
            $this->imgproxy('https://cdn.test/a/red-balloon.jpg'),
            $this->imgproxy('https://cdn.test/b/blue-whale.jpg'),
        ));
    }

    public function testDoesNotMatchTwoDifferentGettyPhotosOnTheLibraryName(): void
    {
        // inthesetimes (#686): two unrelated photos are both `GettyImages-<id>`.
        // The only identity is the numeric id; the word `gettyimages` is noise and
        // must not, on its own, tie the lead to a different in-body picture.
        self::assertFalse($this->sameImage(
            'https://s3.us-east-1.amazonaws.com/in-these-times/GettyImages-2272241474_1.jpg',
            'https://s3.us-east-2.amazonaws.com/itt-images/GettyImages-2251914887.jpeg',
        ));
    }

    public function testStillMatchesTheSameGettyPhotoThroughAProxy(): void
    {
        // …while the same Getty photo, direct and imgproxy-wrapped, still matches
        // on its numeric id.
        $direct = 'https://s3.us-east-1.amazonaws.com/in-these-times/GettyImages-2272241474_1.jpg';
        self::assertTrue($this->sameImage($direct, $this->imgproxy($direct)));
    }

    public function testLeavesANonDecodableSegmentAlone(): void
    {
        // A long path segment that is not base64-of-an-http-url is not a proxy
        // wrapper; it must fall back to today's behaviour, not a spurious match.
        self::assertFalse($this->sameImage(
            'https://cdn.test/aGVsbG8gd29ybGQ.jpg',
            'https://s3.amazonaws.com/bucket/real-photograph-name.jpg',
        ));
    }
}
