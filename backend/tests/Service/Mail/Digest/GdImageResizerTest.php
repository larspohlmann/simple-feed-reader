<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Service\Mail\Digest\Exception\ImageProcessingException;
use App\Service\Mail\Digest\GdImageResizer;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** A red-left / blue-right split so cropping, scaling and centring are all pixel-visible. */
    private function splitColourJpeg(int $width, int $height): string
    {
        if ($width < 1 || $height < 1) {
            self::fail('Test source dimensions must be positive.');
        }

        $image = imagecreatetruecolor($width, $height);
        $red = imagecolorallocate($image, 220, 20, 20);
        $blue = imagecolorallocate($image, 20, 20, 220);
        if ($red === false || $blue === false) {
            self::fail('GD could not allocate the test fill colours.');
        }
        imagefilledrectangle($image, 0, 0, \intdiv($width, 2) - 1, $height - 1, $red);
        imagefilledrectangle($image, \intdiv($width, 2), 0, $width - 1, $height - 1, $blue);
        ob_start();
        imagejpeg($image, null, 100);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function decode(string $bytes): \GdImage
    {
        $image = imagecreatefromstring($bytes);
        if ($image === false) {
            self::fail('Could not decode the resizer output.');
        }

        return $image;
    }

    /** @return array{r: int, g: int, b: int, a: int} */
    private function pixel(\GdImage $image, int $x, int $y): array
    {
        $color = imagecolorat($image, $x, $y);

        return [
            'r' => ($color >> 16) & 0xFF,
            'g' => ($color >> 8) & 0xFF,
            'b' => $color & 0xFF,
            'a' => ($color >> 24) & 0x7F,
        ];
    }

    public function testCoverJpegCoversTheWholeCanvasByCroppingTheWiderSourceSides(): void
    {
        // 200x100 source into a 100x100 target: scale must be max(0.5, 1) = 1,
        // so the canvas is fully covered and only the left/right edges are cropped.
        $out = (new GdImageResizer())->coverJpeg($this->splitColourJpeg(200, 100), 100, 100);
        $image = $this->decode($out);

        $left = $this->pixel($image, 20, 50);
        $right = $this->pixel($image, 80, 50);
        $corner = $this->pixel($image, 0, 0);

        self::assertGreaterThan($left['b'] + 40, $left['r'], 'The left sample must read as the red half.');
        self::assertGreaterThan($right['r'] + 40, $right['b'], 'The right sample must read as the blue half.');
        self::assertFalse(
            $corner['r'] < 10 && $corner['g'] < 10 && $corner['b'] < 10,
            'A wrong (too small) scale would leave an unfilled black border in the corner.',
        );
    }

    public function testContainPngFitsTheWiderSourceInsideWithTransparentBars(): void
    {
        // 200x100 source into a 100x100 target: scale must be min(0.5, 1) = 0.5,
        // so the drawn image is 100x50, centred with a 25px transparent bar top and bottom.
        $out = (new GdImageResizer())->containPng($this->splitColourJpeg(200, 100), 100, 100);
        $image = $this->decode($out);

        $left = $this->pixel($image, 20, 50);
        $right = $this->pixel($image, 80, 50);
        $topBar = $this->pixel($image, 50, 10);
        $bottomBar = $this->pixel($image, 50, 90);

        self::assertGreaterThan($left['b'] + 40, $left['r'], 'The left sample must read as the red half.');
        self::assertGreaterThan($right['r'] + 40, $right['b'], 'The right sample must read as the blue half.');
        self::assertSame(127, $topBar['a'], 'The top bar must be fully transparent, not stretched-in content.');
        self::assertSame(127, $bottomBar['a'], 'The bottom bar must be fully transparent, not stretched-in content.');
    }

    public function testContainPngLeavesTheDrawnAreaFullyOpaque(): void
    {
        $out = (new GdImageResizer())->containPng($this->splitColourJpeg(200, 100), 100, 100);
        $image = $this->decode($out);

        self::assertSame(0, $this->pixel($image, 50, 50)['a'], 'The drawn image area must be fully opaque.');
    }

    /**
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function invalidDimensions(): iterable
    {
        yield 'zero width' => [0, 100];
        yield 'zero height' => [100, 0];
        yield 'negative width' => [-1, 100];
        yield 'negative height' => [100, -1];
    }

    #[DataProvider('invalidDimensions')]
    public function testCoverJpegRejectsNonPositiveDimensions(int $width, int $height): void
    {
        $this->expectException(ImageProcessingException::class);
        (new GdImageResizer())->coverJpeg($this->sourceJpeg(10, 10), $width, $height);
    }

    #[DataProvider('invalidDimensions')]
    public function testContainPngRejectsNonPositiveDimensions(int $width, int $height): void
    {
        $this->expectException(ImageProcessingException::class);
        (new GdImageResizer())->containPng($this->sourceJpeg(10, 10), $width, $height);
    }

    public function testASingleTargetPixelIsAllowed(): void
    {
        $out = (new GdImageResizer())->coverJpeg($this->sourceJpeg(10, 10), 1, 1);

        $size = getimagesizefromstring($out);
        if ($size === false) {
            throw new \RuntimeException('Could not read the output image size.');
        }
        self::assertSame([1, 1], [$size[0], $size[1]]);
    }
}
