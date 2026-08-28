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
}
