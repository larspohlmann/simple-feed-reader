<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

final class PngImageFactory
{
    public static function bytes(int $width, int $height): string
    {
        if ($width < 1 || $height < 1) {
            Assert::fail('PNG fixture dimensions must be positive.');
        }

        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
