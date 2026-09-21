<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Service\Image\ImageDimensions;
use App\Tests\Support\PngImageFactory;
use PHPUnit\Framework\TestCase;

final class ImageDimensionsTest extends TestCase
{
    public function testReadsWidthAndHeightFromPngBytes(): void
    {
        $dimensions = ImageDimensions::fromBytes(PngImageFactory::bytes(320, 200));

        self::assertNotNull($dimensions);
        self::assertSame(320, $dimensions->width);
        self::assertSame(200, $dimensions->height);
    }

    public function testReturnsNullForUndecodableBytes(): void
    {
        self::assertNull(ImageDimensions::fromBytes('this is not an image'));
    }

    public function testIsBeaconIsTrueForABeacon(): void
    {
        $dimensions = ImageDimensions::fromBytes(PngImageFactory::bytes(1, 1));

        self::assertNotNull($dimensions);
        self::assertTrue($dimensions->isBeacon());
    }

    public function testIsBeaconIsFalseWhenOnlyWidthExceedsTheCeiling(): void
    {
        $dimensions = ImageDimensions::fromBytes(PngImageFactory::bytes(101, 100));

        self::assertNotNull($dimensions);
        self::assertFalse($dimensions->isBeacon());
    }

    public function testIsBeaconIsTrueWhenBothEdgesEqualTheCeiling(): void
    {
        $dimensions = ImageDimensions::fromBytes(PngImageFactory::bytes(100, 100));

        self::assertNotNull($dimensions);
        self::assertTrue($dimensions->isBeacon());
    }

    public function testIsBeaconIsFalseWhenOnlyHeightExceedsTheCeiling(): void
    {
        $dimensions = ImageDimensions::fromBytes(PngImageFactory::bytes(100, 101));

        self::assertNotNull($dimensions);
        self::assertFalse($dimensions->isBeacon());
    }
}
