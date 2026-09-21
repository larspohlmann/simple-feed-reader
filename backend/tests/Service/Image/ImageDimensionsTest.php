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

    public function testBothEdgesAtMostIsTrueForABeacon(): void
    {
        $dimensions = ImageDimensions::fromBytes(PngImageFactory::bytes(1, 1));

        self::assertNotNull($dimensions);
        self::assertTrue($dimensions->bothEdgesAtMost(100));
    }

    public function testBothEdgesAtMostIsFalseWhenOneEdgeExceeds(): void
    {
        $dimensions = ImageDimensions::fromBytes(PngImageFactory::bytes(134, 76));

        self::assertNotNull($dimensions);
        self::assertFalse($dimensions->bothEdgesAtMost(100));
    }

    public function testBothEdgesAtMostIsTrueWhenBothEdgesEqualTheEdge(): void
    {
        $dimensions = ImageDimensions::fromBytes(PngImageFactory::bytes(100, 100));

        self::assertNotNull($dimensions);
        self::assertTrue($dimensions->bothEdgesAtMost(100));
    }
}
