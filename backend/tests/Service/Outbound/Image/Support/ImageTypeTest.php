<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\Support;

use App\Service\Image\Support\ImageType;
use PHPUnit\Framework\TestCase;

final class ImageTypeTest extends TestCase
{
    public function testAnEmptyOrOctetStreamTypeIsUndeclared(): void
    {
        self::assertTrue(ImageType::isUndeclared(''));
        self::assertTrue(ImageType::isUndeclared('application/octet-stream'));
        self::assertFalse(ImageType::isUndeclared('image/png'));
    }

    public function testADeclaredTypeIsKeptWhateverTheBytesShow(): void
    {
        self::assertSame('image/png', ImageType::declaredOrSniffed('image/png', 'GIF89a'));
    }

    public function testAnUndeclaredTypeIsSniffedFromTheBytes(): void
    {
        self::assertSame('image/gif', ImageType::declaredOrSniffed('application/octet-stream', 'GIF89a'));
        self::assertSame('image/png', ImageType::declaredOrSniffed('', "\x89PNG\r\n"));
    }

    public function testAnUndeclaredTypeStaysWhenTheBytesShowNoImage(): void
    {
        $type = ImageType::declaredOrSniffed('application/octet-stream', '<svg/>');

        self::assertSame('application/octet-stream', $type);
    }
}
