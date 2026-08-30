<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Service\Mail\Digest\Exception\ImageProcessingException;

/**
 * Rasterises a fetched image to a fixed digest thumbnail (cover-crop JPEG) or
 * favicon (contained PNG) with GD. The source is bounded before decode so a
 * small-bytes / huge-pixels image cannot exhaust the memory limit.
 */
final readonly class GdImageResizer implements DigestImageResizerInterface
{
    public function __construct(private int $maxSourcePixels = 25_000_000)
    {
    }

    public function coverJpeg(string $sourceBytes, int $width, int $height): string
    {
        $this->assertPositiveDimensions($width, $height);
        $source = $this->decode($sourceBytes);
        $canvas = imagecreatetruecolor($width, $height);

        $scale = max($width / imagesx($source), $height / imagesy($source));
        $this->drawCentred($canvas, $source, $scale, $width, $height);

        $out = $this->capture(static fn (\GdImage $image): bool => imagejpeg($image, null, 72), $canvas);
        imagedestroy($source);
        imagedestroy($canvas);

        return $out;
    }

    public function containPng(string $sourceBytes, int $width, int $height): string
    {
        $this->assertPositiveDimensions($width, $height);
        $source = $this->decode($sourceBytes);
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, $this->allocateTransparent($canvas));

        $scale = min($width / imagesx($source), $height / imagesy($source));
        $this->drawCentred($canvas, $source, $scale, $width, $height);

        $out = $this->capture(static fn (\GdImage $image): bool => imagepng($image), $canvas);
        imagedestroy($source);
        imagedestroy($canvas);

        return $out;
    }

    /** @phpstan-assert positive-int $width
     *  @phpstan-assert positive-int $height */
    private function assertPositiveDimensions(int $width, int $height): void
    {
        if ($width < 1 || $height < 1) {
            throw new ImageProcessingException('Target dimensions must be positive.');
        }
    }

    private function allocateTransparent(\GdImage $canvas): int
    {
        $color = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        if ($color === false) {
            throw new ImageProcessingException('GD could not allocate the transparent fill colour.');
        }

        return $color;
    }

    private function decode(string $bytes): \GdImage
    {
        $size = getimagesizefromstring($bytes);
        if ($size === false || $size[0] * $size[1] > $this->maxSourcePixels) {
            throw new ImageProcessingException('Source image is undecodable or exceeds the pixel cap.');
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new ImageProcessingException('GD could not decode the source image.');
        }

        return $image;
    }

    private function drawCentred(\GdImage $canvas, \GdImage $source, float $scale, int $width, int $height): void
    {
        $targetWidth = max(1, (int) round(imagesx($source) * $scale));
        $targetHeight = max(1, (int) round(imagesy($source) * $scale));
        $offsetX = (int) (($width - $targetWidth) / 2);
        $offsetY = (int) (($height - $targetHeight) / 2);

        imagecopyresampled(
            $canvas,
            $source,
            $offsetX,
            $offsetY,
            0,
            0,
            $targetWidth,
            $targetHeight,
            imagesx($source),
            imagesy($source),
        );
    }

    /** @param callable(\GdImage): bool $encode */
    private function capture(callable $encode, \GdImage $canvas): string
    {
        ob_start();
        $encode($canvas);

        return (string) ob_get_clean();
    }
}
