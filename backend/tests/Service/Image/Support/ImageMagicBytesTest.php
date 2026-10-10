<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\Support;

use App\Service\Image\Support\ImageMagicBytes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageMagicBytesTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function leadingBytes(): iterable
    {
        yield 'jpeg' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF", 'image/jpeg'];
        yield 'png' => ["\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR", 'image/png'];
        yield 'gif87a' => ['GIF87a', 'image/gif'];
        yield 'gif89a' => ['GIF89a', 'image/gif'];
        yield 'webp' => ["RIFF\x24\x00\x00\x00WEBPVP8 ", 'image/webp'];
        yield 'avif' => ["\x00\x00\x00\x1CftypavifmiaF", 'image/avif'];
        yield 'avif sequence' => ["\x00\x00\x00\x20ftypavis", 'image/avif'];
        yield 'heic' => ["\x00\x00\x00\x18ftypheic", 'image/heic'];
        yield 'heix' => ["\x00\x00\x00\x18ftypheix", 'image/heic'];
        yield 'riff that is not webp' => ["RIFF\x24\x00\x00\x00WAVEfmt ", null];
        yield 'mp4 ftyp box' => ["\x00\x00\x00\x18ftypisom", null];
        yield 'jpeg marker cut short' => ["\xFF\xD8", null];
        yield 'png signature cut short' => ["\x89PN", null];
        yield 'html' => ['<!doctype html><title>x</title>', null];
        yield 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"/>', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('leadingBytes')]
    public function testTheLeadingBytesNameTheImageType(string $bytes, ?string $expected): void
    {
        self::assertSame($expected, ImageMagicBytes::typeOf($bytes));
    }

    public function testTheSignatureMustStartTheBody(): void
    {
        self::assertNull(ImageMagicBytes::typeOf("x\xFF\xD8\xFF\xE0"));
        self::assertNull(ImageMagicBytes::typeOf(" GIF89a"));
        self::assertNull(ImageMagicBytes::typeOf("\x00RIFF\x24\x00\x00\x00WEBP"));
    }
}
