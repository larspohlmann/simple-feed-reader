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

    public function testSameAssetRejectsDifferentShortNumericAssetTokens(): void
    {
        $first = ImageIdentity::fromUrl('https://cdn.test/article-prefix_1234.jpg');
        $second = ImageIdentity::fromUrl('https://cdn.test/article-prefix_5678.jpg');

        self::assertFalse($first->isSameAsset($second));
    }

    public function testSameAssetRejectsDifferentAssetsWithTheSameSizeSuffix(): void
    {
        $first = ImageIdentity::fromUrl('https://cdn.test/article-prefix_11111-1280x720.jpg');
        $second = ImageIdentity::fromUrl('https://cdn.test/article-prefix_22222-1280x720.jpg');

        self::assertFalse($first->isSameAsset($second));
    }

    public function testSameAssetAcceptsTheSameAssetWithASizeSuffix(): void
    {
        $original = ImageIdentity::fromUrl('https://cdn.test/article-prefix_11111.jpg');
        $sized = ImageIdentity::fromUrl('https://cdn.test/article-prefix_11111-1280x720.webp');

        self::assertTrue($original->isSameAsset($sized));
    }

    public function testSameAssetRejectsDifferentExplicitIdsWithTheSameStem(): void
    {
        $first = ImageIdentity::fromUrl('https://cdn.test/image.jpg?imageId=11111');
        $second = ImageIdentity::fromUrl('https://cdn.test/image.jpg?imageId=22222');

        self::assertFalse($first->isSameAsset($second));
    }

    public function testSameAssetAcceptsTheSameStemWhenOnlyOneUrlHasAnExplicitId(): void
    {
        $withId = ImageIdentity::fromUrl('https://cdn.test/image.jpg?imageId=11111');
        $withoutId = ImageIdentity::fromUrl('https://cdn.test/image.jpg');

        self::assertTrue($withId->isSameAsset($withoutId));
    }

    public function testSameAssetDoesNotTreatAPrefixedDimensionAsASizeSuffix(): void
    {
        $first = ImageIdentity::fromUrl('https://cdn.test/article-prefix_11111-foo1280x720.jpg');
        $second = ImageIdentity::fromUrl('https://cdn.test/article-prefix_22222-foo1280x720.jpg');

        self::assertTrue($first->isSameAsset($second));
    }

    public function testSameAssetDoesNotTreatASuffixedDimensionAsASizeSuffix(): void
    {
        $first = ImageIdentity::fromUrl('https://cdn.test/article-prefix_11111-1280x720foo.jpg');
        $second = ImageIdentity::fromUrl('https://cdn.test/article-prefix_22222-1280x720foo.jpg');

        self::assertTrue($first->isSameAsset($second));
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

    public function testIsSameAssetAcceptsTheSamePathUuidAcrossRenditions(): void
    {
        // tagesschau 491512: the video poster and the body img share a path UUID
        // but differ in every other respect (rendition folder, filename).
        $poster = ImageIdentity::fromUrl(
            'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/AAAAAA/16x9-1920/poster.jpg',
        );
        $bodyImg = ImageIdentity::fromUrl(
            'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/BBBBBB/16x9-big/thumb.jpg',
        );

        self::assertTrue($poster->isSameAsset($bodyImg));
    }

    public function testMatchesAlsoAcceptsTheSamePathUuidAcrossRenditions(): void
    {
        // matches() is strengthened the same way: this is what lets
        // ReaderLeadImage::restore() correctly skip the tagesschau hero.
        $poster = ImageIdentity::fromUrl(
            'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/AAAAAA/16x9-1920/poster.jpg',
        );
        $bodyImg = ImageIdentity::fromUrl(
            'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/BBBBBB/16x9-big/thumb.jpg',
        );

        self::assertTrue($poster->matches($bodyImg));
    }

    public function testMatchesRejectsTheSameStemUnderDifferentPathUuids(): void
    {
        // Intended, not a bug: the pathUuid branch pre-empts the stem check,
        // so a same-named "sendungsbild.jpg" under two different CMS asset
        // UUIDs no longer matches even though the stems agree. The only
        // consequence flows through PageImageInventory::draws() into
        // ReaderLeadImage::restore(): the lead is SKIPPED (an omitted image),
        // never duplicated — a different UUID genuinely is a different asset.
        $first = ImageIdentity::fromUrl(
            'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/AAAAAA/sendungsbild.jpg',
        );
        $second = ImageIdentity::fromUrl(
            'https://media.tagesschau.de/image/58e272fd-1234-5678-9abc-def012345678/AAAAAA/sendungsbild.jpg',
        );

        self::assertFalse($first->matches($second));
    }

    public function testIsSameAssetFallsBackToStemWhenOnlyOneSideHasAPathUuid(): void
    {
        // #681 regression pin (54 articles): the UUID branch only fires when
        // BOTH sides have a path UUID. One-sided UUID presence must fall
        // through to the stem check, not treat the UUID-less side as having
        // no identity at all.
        $withPathUuid = ImageIdentity::fromUrl(
            'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/AAAAAA/photo-story.jpg',
        );
        $withImageId = ImageIdentity::fromUrl('https://cdn.example.com/img/photo-story.jpg?imageid=XYZ123');

        self::assertTrue($withPathUuid->isSameAsset($withImageId));
    }

    public function testIsSameAssetRejectsDifferentPathUuids(): void
    {
        // tagesschau 491912: video2's UUID has no matching body img.
        $video1 = ImageIdentity::fromUrl(
            'https://media.tagesschau.de/image/80085f9c-1234-5678-9abc-def012345678/AAAAAA/16x9-1920/poster.jpg',
        );
        $video2 = ImageIdentity::fromUrl(
            'https://media.tagesschau.de/image/58e272fd-1234-5678-9abc-def012345678/AAAAAA/16x9-1920/thumb.jpg',
        );

        self::assertFalse($video1->isSameAsset($video2));
    }

    public function testIsSameAssetIgnoresAUuidShapedMatchWhenTokensDisagree(): void
    {
        // The UUID rule must take precedence even when a shared token would
        // otherwise have suggested a match: two distinct assets under the same
        // UUID-bearing path family stay distinct once the ids differ.
        $first = ImageIdentity::fromUrl(
            'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/AAAAAA/bild-photo.jpg',
        );
        $second = ImageIdentity::fromUrl(
            'https://media.tagesschau.de/image/58e272fd-1234-5678-9abc-def012345678/AAAAAA/bild-photo.jpg',
        );

        self::assertFalse($first->isSameAsset($second));
    }

    public function testDoesNotTreatATransformHashSegmentAsAUuid(): void
    {
        // A per-rendition transform hash is not 8-4-4-4-12 hex shaped; it must
        // not be mistaken for an asset id and must fall back to stem/token
        // matching, exactly like today.
        $first = ImageIdentity::fromUrl('https://cdn.test/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4/vegane-burrata-photo.jpg');
        $second = ImageIdentity::fromUrl('https://cdn.test/f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3/vegane-burrata-photo.jpg');

        self::assertTrue($first->isSameAsset($second));
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

    public function testMatchesTheSamePhotoThroughAPercentEncodedPathProxy(): void
    {
        // Substack (#786): every rendition is `substackcdn.com/image/fetch/<transforms>/<source>`
        // with the source URL percent-encoded as the final path segment.
        $source = 'https://substack-post-media.s3.amazonaws.com/public/images/'
            . 'f900b552-ad73-4892-a3b7-d300ad0de90e_1280x1280.png';
        self::assertTrue($this->sameImage(
            $this->substackFetch('$s_!8qHS!,w_120,h_120,c_fill,f_webp,q_auto:good', $source),
            $this->substackFetch('$s_!9Uw9!,f_auto,q_auto:best,fl_progressive:steep', $source),
        ));
    }

    public function testDoesNotMatchTwoDifferentPhotosBehindAPercentEncodedPathProxy(): void
    {
        // Without the unwrap both stems are the whole encoded source, and the
        // generic words "https"/"substack" in every one of them tie the
        // publication's subscribe card to any avatar on the page (#786).
        $card = $this->substackFetch(
            '$s_!9Uw9!,f_auto,q_auto:best,fl_progressive:steep',
            'https://charleseisenstein.substack.com/twitter/subscribe-card.jpg?v=538695404&version=9',
        );
        $avatar = $this->substackFetch(
            '$s_!8qHS!,w_120,h_120,c_fill,f_webp,q_auto:good,fl_progressive:steep',
            'https://substack-post-media.s3.amazonaws.com/public/images/'
            . 'f900b552-ad73-4892-a3b7-d300ad0de90e_1280x1280.png',
        );

        self::assertFalse($this->sameImage($card, $avatar));
        self::assertFalse(ImageIdentity::fromUrl($card)->matches(ImageIdentity::fromUrl($avatar)));
    }

    public function testNamesAShareRenderByItsCardFilename(): void
    {
        // Substack's og:image on a post without pictures is the publication's
        // subscribe card, wrapped by the fetch proxy (#786).
        $card = $this->substackFetch(
            '$s_!9Uw9!,f_auto,q_auto:best,fl_progressive:steep',
            'https://charleseisenstein.substack.com/twitter/subscribe-card.jpg?v=538695404&version=9',
        );

        self::assertTrue($this->isShareRender($card));
    }

    public function testNamesAShareRenderByItsPreviewDirectory(): void
    {
        // trance-nexus and stitcher.io: a generated preview under /og/ or /meta/.
        self::assertTrue($this->isShareRender('https://trance-nexus.test/og/blog/best-tracks-2026.png'));
        self::assertTrue($this->isShareRender('https://stitcher.test/meta/meta_lg.png'));
        self::assertTrue($this->isShareRender('https://pub.test/images/og-image.png'));
    }

    public function testDoesNotNameAPhotoAShareRender(): void
    {
        self::assertFalse($this->isShareRender('https://pub.test/uploads/2026/09/260902-WEB-1200x630.jpg'));
        self::assertFalse($this->isShareRender('https://pub.test/photos/postcard-from-oslo.jpg'));
        self::assertFalse($this->isShareRender('https://pub.test/omega/meta-analysis-chart.png'));
    }

    public function testDoesNotMatchTwoImagesThatShareOnlyARenditionSize(): void
    {
        // thevale (#786): two square 1080x1080 uploads are different pictures; a
        // dimension word names a rendition size, never a photo.
        $uploads = 'https://substack-post-media.s3.amazonaws.com/public/images/';
        self::assertFalse($this->sameImage(
            $uploads . 'fb82b0a8-1392-454b-b4b2-ae77a04c9fab_1080x1080.png',
            $uploads . '13507ae0-f034-4b58-ae2d-d048a0fa290c_1080x1080.png',
        ));
    }

    private function isShareRender(string $url): bool
    {
        return ImageIdentity::fromUrl($url)->isShareRender();
    }

    private function substackFetch(string $transforms, string $source): string
    {
        return 'https://substackcdn.com/image/fetch/' . $transforms . '/' . rawurlencode($source);
    }
}
