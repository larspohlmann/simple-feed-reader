<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Service\Mail\Digest\Exception\ImageProcessingException;
use App\Service\Mail\Digest\GdImageResizer;
use PHPUnit\Framework\TestCase;

final class GdImageResizerTest extends TestCase
{
    private function sourceJpeg(int $width, int $height): string
    {
        if ($width < 1 || $height < 1) {
            self::fail('Test source dimensions must be positive.');
        }

        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 200, 100, 50);
        if ($color === false) {
            self::fail('GD could not allocate the test fill colour.');
        }
        imagefill($image, 0, 0, $color);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function testCoverJpegProducesExactTargetDimensions(): void
    {
        $out = (new GdImageResizer())->coverJpeg($this->sourceJpeg(400, 300), 176, 132);

        $size = getimagesizefromstring($out);
        if ($size === false) {
            throw new \RuntimeException('Could not read the output image size.');
        }
        [$width, $height] = $size;
        self::assertSame(176, $width);
        self::assertSame(132, $height);
    }

    public function testContainPngProducesExactTargetDimensions(): void
    {
        $out = (new GdImageResizer())->containPng($this->sourceJpeg(64, 32), 32, 32);

        $size = getimagesizefromstring($out);
        if ($size === false) {
            throw new \RuntimeException('Could not read the output image size.');
        }
        [$width, $height, $type] = $size;
        self::assertSame(32, $width);
        self::assertSame(32, $height);
        self::assertSame(IMAGETYPE_PNG, $type);
    }

    public function testUndecodableBytesThrow(): void
    {
        $this->expectException(ImageProcessingException::class);
        (new GdImageResizer())->coverJpeg('not an image', 176, 132);
    }

    public function testOversizedSourceThrows(): void
    {
        $this->expectException(ImageProcessingException::class);
        (new GdImageResizer(maxSourcePixels: 100))->coverJpeg($this->sourceJpeg(400, 300), 176, 132);
    }
}
