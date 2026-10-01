<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\Model;

use App\Entity\ImageRendition;
use App\Service\Image\Model\DeclaredImageModel;
use PHPUnit\Framework\TestCase;

final class DeclaredImageModelTest extends TestCase
{
    public function testDeclaresBeaconIsTrueForAOnePixelImage(): void
    {
        self::assertTrue((new DeclaredImageModel('https://i/x.jpg', 1, 1))->declaresBeacon());
    }

    public function testDeclaresBeaconIsTrueWhenBothEdgesEqualTheCeiling(): void
    {
        self::assertTrue((new DeclaredImageModel('https://i/x.jpg', 100, 100))->declaresBeacon());
    }

    public function testDeclaresBeaconIsFalseWhenAnEdgeExceedsTheCeiling(): void
    {
        self::assertFalse((new DeclaredImageModel('https://i/x.jpg', 101, 100))->declaresBeacon());
    }

    public function testDeclaresBeaconIsFalseWithOnlyOneDimension(): void
    {
        self::assertFalse((new DeclaredImageModel('https://i/x.jpg', 1, null))->declaresBeacon());
    }

    public function testDeclaresBeaconIsFalseWithNoDimensions(): void
    {
        self::assertFalse((new DeclaredImageModel('https://i/x.jpg', null, null))->declaresBeacon());
    }

    private const string GUARDIAN_PHOTO =
        'https://i.guim.co.uk/img/media/f6d33de551f7fcdc046178cfccc4037e79b99f3e/276_0_4639_3711/master/4639.jpg';

    private const string SUBSTACK_SOURCE = 'https%3A%2F%2Fsubstack-post-media.s3.amazonaws.com%2Fpublic%2Fimages'
        . '%2F10a5f3c6-6b92-48ff-8280-0cd3a9f25e41_750x1054.jpeg';

    private static function sized(string $url, int $width, ?int $height = null): DeclaredImageModel
    {
        return new DeclaredImageModel($url, $width, $height, [new ImageRendition($url, $width)]);
    }

    private static function substack(string $transforms): string
    {
        return 'https://substackcdn.com/image/fetch/$s_!v2GA!,' . $transforms . '/' . self::SUBSTACK_SOURCE;
    }

    public function testJoinsGuardianWidthsOfOnePathDespiteTheirSignedQueries(): void
    {
        $wide = self::sized(self::GUARDIAN_PHOTO . '?width=700&quality=85&auto=format&fit=max&s=6192bfa4', 700);
        $narrow = self::sized(self::GUARDIAN_PHOTO . '?width=140&quality=85&auto=format&fit=max&s=406198660', 140);

        self::assertEquals(
            [...$wide->renditions, ...$narrow->renditions],
            $wide->joinedWith($narrow)->renditions,
        );
    }

    public function testKeepsAnotherGuardianPhotoApart(): void
    {
        $wide = self::sized(self::GUARDIAN_PHOTO . '?width=700&s=6192bfa4', 700);
        $other = self::sized(
            'https://i.guim.co.uk/img/media/0d73ce3f33e41d2cc83104a798beda3a6a56487f/344_0_3409_2726/master/3409.jpg'
            . '?width=140&s=b77e32d9',
            140,
        );

        self::assertEquals($wide->renditions, $wide->joinedWith($other)->renditions);
    }

    public function testASubstackEnclosureTakesTheLadderOfTheBodyImageWrappingTheSameSource(): void
    {
        $enclosure = new DeclaredImageModel(self::substack('f_auto,q_auto:good,fl_progressive:steep'));
        $body = new DeclaredImageModel(
            self::substack('w_1456,c_limit,f_auto,q_auto:good,fl_progressive:steep'),
            750,
            1054,
            [
                new ImageRendition(self::substack('w_424,c_limit,f_auto,q_auto:good,fl_progressive:steep'), 424),
                new ImageRendition(self::substack('w_848,c_limit,f_auto,q_auto:good,fl_progressive:steep'), 848),
            ],
        );

        $joined = $enclosure->joinedWith($body);

        self::assertSame($enclosure->url, $joined->url);
        self::assertNull($joined->width);
        self::assertEquals($body->renditions, $joined->renditions);
    }

    public function testJoinsAWordPressSizeOfTheSameUpload(): void
    {
        $full = self::sized('https://cdn.example/wp-content/uploads/2026/09/funk-system-1800.jpg', 1800, 1200);
        $large = new DeclaredImageModel(
            'https://cdn.example/wp-content/uploads/2026/09/funk-system-1800-1024x683.jpg',
            1024,
            683,
            [new ImageRendition('https://cdn.example/wp-content/uploads/2026/09/funk-system-1800-300x200.jpg', 300)],
        );

        self::assertEquals(
            [...$full->renditions, ...$large->renditions],
            $full->joinedWith($large)->renditions,
        );
    }

    public function testKeepsASquareCropOfTheSameAssetOutInBothDirections(): void
    {
        $landscape = self::sized(
            'https://cdn.arstechnica.net/wp-content/uploads/2026/09/GettyImages-1042124682-1152x648.jpg',
            1152,
            648,
        );
        $square = self::sized(
            'https://cdn.arstechnica.net/wp-content/uploads/2026/09/GettyImages-1042124682-500x500.jpg',
            500,
            500,
        );

        self::assertEquals($landscape->renditions, $landscape->joinedWith($square)->renditions);
        self::assertEquals($square->renditions, $square->joinedWith($landscape)->renditions);
    }

    public function testJoiningItselfAddsNothing(): void
    {
        $image = self::sized('https://i/photo-landscape.jpg', 700);

        self::assertEquals($image->renditions, $image->joinedWith($image)->renditions);
    }
}
