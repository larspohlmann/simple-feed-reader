<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Service\Image\ImageDimensions;
use PHPUnit\Framework\TestCase;

final class ImageDimensionsTest extends TestCase
{
    private function pngBytes(int $width, int $height): string
    {
        if ($width < 1 || $height < 1) {
            self::fail('Test source dimensions must be positive.');
        }

        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    public function testReadsWidthAndHeightFromPngBytes(): void
    {
        $dimensions = ImageDimensions::fromBytes($this->pngBytes(320, 200));

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
        $dimensions = ImageDimensions::fromBytes($this->pngBytes(1, 1));

        self::assertNotNull($dimensions);
        self::assertTrue($dimensions->bothEdgesAtMost(100));
    }

    public function testBothEdgesAtMostIsFalseWhenOneEdgeExceeds(): void
    {
        $dimensions = ImageDimensions::fromBytes($this->pngBytes(134, 76));

        self::assertNotNull($dimensions);
        self::assertFalse($dimensions->bothEdgesAtMost(100));
    }

    public function testBothEdgesAtMostIsTrueWhenBothEdgesEqualTheEdge(): void
    {
        $dimensions = ImageDimensions::fromBytes($this->pngBytes(100, 100));

        self::assertNotNull($dimensions);
        self::assertTrue($dimensions->bothEdgesAtMost(100));
    }
}
