<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\Model;

use App\Service\Image\Model\ImageDimensionsModel;
use App\Tests\Support\PngImageFactory;
use PHPUnit\Framework\TestCase;

final class ImageDimensionsModelTest extends TestCase
{
    public function testReadsWidthAndHeightFromPngBytes(): void
    {
        $dimensions = ImageDimensionsModel::fromBytes(PngImageFactory::bytes(320, 200));

        self::assertNotNull($dimensions);
        self::assertSame(320, $dimensions->width);
        self::assertSame(200, $dimensions->height);
    }

    public function testReturnsNullForUndecodableBytes(): void
    {
        self::assertNull(ImageDimensionsModel::fromBytes('this is not an image'));
    }

    public function testIsBeaconIsTrueForABeacon(): void
    {
        $dimensions = ImageDimensionsModel::fromBytes(PngImageFactory::bytes(1, 1));

        self::assertNotNull($dimensions);
        self::assertTrue($dimensions->isBeacon());
    }

    public function testIsBeaconIsFalseWhenOnlyWidthExceedsTheCeiling(): void
    {
        $dimensions = ImageDimensionsModel::fromBytes(PngImageFactory::bytes(101, 100));

        self::assertNotNull($dimensions);
        self::assertFalse($dimensions->isBeacon());
    }

    public function testIsBeaconIsTrueWhenBothEdgesEqualTheCeiling(): void
    {
        $dimensions = ImageDimensionsModel::fromBytes(PngImageFactory::bytes(100, 100));

        self::assertNotNull($dimensions);
        self::assertTrue($dimensions->isBeacon());
    }

    public function testIsBeaconIsFalseWhenOnlyHeightExceedsTheCeiling(): void
    {
        $dimensions = ImageDimensionsModel::fromBytes(PngImageFactory::bytes(100, 101));

        self::assertNotNull($dimensions);
        self::assertFalse($dimensions->isBeacon());
    }
}
